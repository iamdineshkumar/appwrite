<?php

use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Resque\Worker;
use Streaming\Format\StreamFormat;
use Streaming\Media;
use Streaming\Metadata;
use Streaming\Representation;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Utopia\Storage\Compression\Algorithms\GZIP;

require_once __DIR__ . '/../init.php';

Console::title('Transcoding V1 Worker');
Console::success(APP_NAME . ' transcoding worker v1 has started');

class TranscodingV1 extends Worker
{
    /**
     * Rendition Status
     */
    const STATUS_TRANSCODE_START     = 'started';
    const STATUS_TRANSCODE_END       = 'ended';
    const STATUS_UPLOADING           = 'uploading';
    const STATUS_PACKAGE_END         = 'ready';
    const STATUS_ERROR               = 'error';

    const STREAM_HLS = 'hls';

    //protected string $basePath = '/tmp/';
    protected string $basePath = '/usr/src/code/tests/tmp/';

    protected string $inDir;

    protected string $outDir;

    protected string $outPath;

    protected string $renditionName;

    protected Database $database;


    public function getName(): string
    {
        return "Transcoding";
    }


    public function init(): void
    {

        $this->basePath .=   $this->args['videoId'] . '/' . $this->args['profileId'];
        $this->inDir  =  $this->basePath . '/in/';
        $this->outDir =  $this->basePath . '/out/';
        @mkdir($this->inDir, 0755, true);
        @mkdir($this->outDir, 0755, true);
        $this->outPath = $this->outDir . $this->args['videoId']; /** TODO figure a way to write dir tree without this **/
    }

    public function run(): void
    {
        $project = new Document($this->args['project']);
        $this->database = $this->getProjectDB($project->getId());

        $sourceVideo = Authorization::skip(fn() => $this->database->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$this->args['videoId']])]));
        if (empty($sourceVideo)) {
            throw new Exception('Video not found');
        }

        $profile = Authorization::skip(fn() => $this->database->findOne('video_profiles', [new Query('_uid', Query::TYPE_EQUAL, [$this->args['profileId']])]));
        if (empty($profile)) {
            throw new Exception('profile not found');
        }

        $bucket = Authorization::skip(fn() => $this->database->getDocument('buckets', $sourceVideo['bucketId']));
        if ($bucket->getAttribute('permission') === 'bucket') {
            $file = Authorization::skip(fn() => $this->database->getDocument('bucket_' . $bucket->getInternalId(), $sourceVideo['fileId']));
        } else {
            $file = $this->database->getDocument('bucket_' . $bucket->getInternalId(), $sourceVideo['fileId']);
        }

        $data = $this->getFilesDevice($project->getId())->read($file->getAttribute('path'));
        $fileName = basename($file->getAttribute('path'));
        $inPath = $this->inDir . $fileName;
        $collection = 'video_renditions';

        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $data = OpenSSL::decrypt(
                $data,
                $file->getAttribute('openSSLCipher'),
                App::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        if (!empty($file->getAttribute('algorithm', ''))) {
            $compressor = new GZIP();
            $data = $compressor->decompress($data);
        }

        $this->getFilesDevice($project->getId())->write($this->inDir . $fileName, $data, $file->getAttribute('mimeType'));

        $ffprobe = FFMpeg\FFProbe::create([]);
        $ffmpeg = Streaming\FFMpeg::create([]);

        if (!$ffprobe->isValid($inPath)) {
            throw new Exception('Not an valid FFMpeg file "' . $inPath . '"');
        }

        //Delete prev rendition
        $queries = [
            new Query('videoId', Query::TYPE_EQUAL, [$sourceVideo->getId()]),
            new Query('profileId', Query::TYPE_EQUAL, [$profile->getId()])
        ];

        $rendition = Authorization::skip(fn() => $this->database->findOne($collection, $queries));

        if (!empty($rendition)) {
            Authorization::skip(fn() => $this->database->deleteDocument($collection, $rendition->getId()));
            $deviceFiles = $this->getVideoDevice($project->getId());
            if (!empty($rendition['path'])) {
                $deviceFiles->deletePath($rendition['path']);
            }
        }

        $general = $this->getVideoInfo($ffprobe->streams($inPath));
        if (!empty($general)) {
            foreach ($general as $key => $value) {
                $sourceVideo->setAttribute($key, $value);
            }

            Authorization::skip(fn() => $this->database->updateDocument(
                'videos',
                $sourceVideo->getId(),
                $sourceVideo
            ));
        }

        $video = $ffmpeg->open($inPath);
        $this->setRenditionName($profile);

        $query = Authorization::skip(function () use ($collection, $profile) {
                    return $this->database->createDocument($collection, new Document([
                        'videoId'  => $this->args['videoId'],
                        'profileId' => $profile->getId(),
                        'name'      => $this->getRenditionName(),
                        'startedAt' => time(),
                        'status'    => self::STATUS_TRANSCODE_START,
                        'stream'    => $profile['stream'],
                    ]));
        });

        try {
            $representation = (new Representation())->
            setKiloBitrate($profile->getAttribute('videoBitrate'))->
            setAudioKiloBitrate($profile->getAttribute('audioBitrate'))->
            setResize($profile->getAttribute('width'), $profile->getAttribute('height'));

            $format = new Streaming\Format\X264();
            $format->on('progress', function ($video, $format, $percentage) use ($query, $collection) {
                if ($percentage % 3 === 0) {
                    $query->setAttribute('progress', (string)$percentage);
                    Authorization::skip(fn() => $this->database->updateDocument(
                        $collection,
                        $query->getId(),
                        $query
                    ));
                }
            });


            list($general, $metadata) = $this->transcode($profile['stream'], $video, $format, $representation);
            if (!empty($metadata)) {
                $query->setAttribute('metadata', json_encode($metadata));
            }

            if (!empty($general)) {
                foreach ($general as $key => $value) {
                    $query->setAttribute($key, $value);
                }
            }

            $query->setAttribute('status', self::STATUS_TRANSCODE_END);
            $query->setAttribute('endedAt', time());
            Authorization::skip(fn() => $this->database->updateDocument(
                $collection,
                $query->getId(),
                $query
            ));

         /** Upload & remove files **/
            $start = 0;
            $fileNames = scandir($this->outDir);

            foreach ($fileNames as $fileName) {
                if (
                    $fileName === '.' ||
                    $fileName === '..' ||
                    str_contains($fileName, '.json')
                ) {
                    continue;
                }

                $deviceFiles  = $this->getVideoDevice($project->getId());
                $devicePath   = $deviceFiles->getPath($this->args['videoId']);
                $data = $this->getFilesDevice($project->getId())->read($this->outDir . $fileName);
                $renditionPath = $devicePath . DIRECTORY_SEPARATOR . $this->getRenditionName();
                $this->getVideoDevice($project->getId())->write($renditionPath . DIRECTORY_SEPARATOR .  $fileName, $data, \mime_content_type($this->outDir . $fileName));
                if ($start === 0) {
                    $query->setAttribute('status', self::STATUS_UPLOADING);
                    $query->setAttribute('path', $renditionPath);
                    Authorization::skip(fn() => $this->database->updateDocument(
                        $collection,
                        $query->getId(),
                        $query
                    ));
                    $start = 1;
                }

                //$metadata=[];
                //$chunksUploaded = $deviceFiles->upload($file, $path, -1, 1, $metadata);
                //var_dump($chunksUploaded);
                // if (empty($chunksUploaded)) {
                //  throw new Exception('Failed uploading file', 500, Exception::GENERAL_SERVER_ERROR);
                //}
                // }

                //@unlink($this->outDir . $fileName);
            }

            $query->setAttribute('status', self::STATUS_PACKAGE_END);
            Authorization::skip(fn() => $this->database->updateDocument(
                $collection,
                $query->getId(),
                $query
            ));
        } catch (\Throwable $th) {
            $query->setAttribute('metadata', json_encode([
            'code' => $th->getCode(),
            'message' => $th->getMessage(),
            ]));

            $query->setAttribute('status', self::STATUS_ERROR);
            Authorization::skip(fn() => $this->database->updateDocument(
                $collection,
                $query->getId(),
                $query
            ));
        }
    }

    /**
     * @param string $stream
     * @param $video Media
     * @param $format StreamFormat
     * @param $representation Representation
     * @return array
     */
    private function transcode(string $stream, Media $video, StreamFormat $format, Representation $representation): string | array
    {

        $additionalParams = [
            '-dn',
            '-sn',
            '-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1'
        ];

        $segementSize = 10;

        if ($stream === 'mpeg-dash') {
                $dash = $video->dash()
                ->setFormat($format)
                ->setSegDuration($segementSize)
                ->addRepresentation($representation)
                ->setAdditionalParams($additionalParams)
                ->save($this->outPath);

                    $xml = simplexml_load_string(
                        file_get_contents($this->outDir . $this->args['videoId'] . '.mpd')
                    );

                $general = $this->getVideoInfo($dash->metadata()->getVideoStreams());
                $general['width'] = $representation->getWidth();
                $general['height'] = $representation->getHeight();
                return [
                         $general,
                        ['mpeg-dash'   => !empty($xml) ? json_decode(json_encode((array)$xml), true) : []],
                      ];
        }


        $hls = $video->hls()
            ->setFormat($format)
            ->setHlsTime($segementSize)
            ->addRepresentation($representation)
            ->setAdditionalParams($additionalParams)
            ->setHlsBaseUrl('http://127.0.0.1/v1/video/' . $this->args['videoId'] . '/' . self::STREAM_HLS . '/' . $this->getRenditionName() . '/')
            ->save($this->outPath);

        $general = $this->getVideoInfo($hls->metadata()->getVideoStreams());
        $general['width'] = $representation->getWidth();
        $general['height'] = $representation->getHeight();

        //$this->rewriteHlsLines($this->outPath . '_' . $this->getRenditionName() . '.m3u8');

        return [
            $general, []
        ];
    }

    private function rewriteHlsLines($path)
    {
        $handle = fopen($path, "r");
        $destination = fopen($path . '_tmp', "w");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $newLine = str_replace(array("\r","\n"), "", $line);
                if (str_contains($line, ".ts")) {
                    $newLine = 'http://127.0.0.1/v1/video/' . $this->args['videoId'] . '/' . self::STREAM_HLS . '/' . $this->getRenditionName() . '/' . $newLine;
                    var_dump($newLine);
                }
                fwrite($destination, $newLine . PHP_EOL);
            }
            var_dump($path . '_tmp -> ' . $path);
            rename($path . '_tmp', $path);
            fclose($handle);
            fclose($destination);
        }
    }

    /**
     * @param $streams StreamCollection
     * @return array
     */
    private function getVideoInfo(StreamCollection $streams): array
    {
        return [
            'duration' => $streams->videos()->first()->get('duration'),
            'height' => $streams->videos()->first()->get('height'),
            'width' => $streams->videos()->first()->get('width'),
            'videoCodec'   => $streams->videos()->first()->get('codec_name') . ',' . $streams->videos()->first()->get('codec_tag_string'),
            'videoFramerate' => $streams->videos()->first()->get('avg_frame_rate'),
            'videoBitrate' =>  (int)$streams->videos()->first()->get('bit_rate'),
            'audioCodec' =>  $streams->audios()->first()->get('codec_name') . ',' . $streams->audios()->first()->get('codec_tag_string'),
            'audioSamplerate' => (int)$streams->audios()->first()->get('sample_rate'),
            'audioBitrate'   =>  (int)$streams->audios()->first()->get('bit_rate'),
        ];
    }

    private function setRenditionName($profile)
    {
        $this->renditionName = $profile->getAttribute('width')
            . 'X' . $profile->getAttribute('height')
            . '@' . ($profile->getAttribute('videoBitrate') + $profile->getAttribute('audioBitrate'));
    }

    private function getRenditionName(): string
    {
        return $this->renditionName;
    }


    private function cleanup(): bool
    {
        var_dump("rm -rf {$this->basePath}");
        //return \exec("rm -rf {$this->basePath}");
        return true;
    }

    public function shutdown(): void
    {
        $this->cleanup();
    }
}