<?php
namespace app\models;

use yii\base\Model;
use Yii;
use yii\helpers\BaseFileHelper;
use yii\imagine\Image;

/**
 * Signup form
 */
class FileUploader extends Model
{
    private $link_to_file;

    private $size;
    private $mime_type;
    private $file;


    private $ratio_w = 450;
    private $ratio_h = 300;

    private $origin_folder = "meals-images";
    private $thumbnail_folder = "meals-images";


    public function __construct($config = [])
    {
        BaseFileHelper::createDirectory($this->origin_folder);
        BaseFileHelper::createDirectory($this->thumbnail_folder);
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['file'], 'file', 'extensions' => 'png, jpg', 'maxSize' => 1024 * 1024 * 2],
            [['file'], 'required'],
        ];
    }

    public function clearFile() {
        unlink($this->link_to_file);
    }

    /**
     * @param $url
     */
    public static function clearFileByLink($url) {
        $url = parse_url($url);
        if (file_exists($url['path'])) {
            unlink($url['path']);
        }
    }

    /**
     * @return mixed
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * @param $file
     * @return mixed
     */
    public function setFile(&$file)
    {
        return $this->file = $file;
    }

    /**
     * @return mixed
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @return mixed
     */
    public function getMimeType()
    {
        return $this->mime_type;
    }


    /**
     * @return string
     */
    public static function getRandName() {
        return time() . Yii::$app->security->generateRandomString(20);
    }

    /**
     * @param $extension
     * @return string
     */
    public function getLinkToFile($extension) {

        $file_name = self::getRandName();
        $this->file = $this->origin_folder . '/' . $file_name . '.' . $extension;
        return $this->file;
    }

    /**
     * @param $file_name
     * @return string
     */
    public function getFullPathToFile($file_name) {
        return Yii::$app->request->hostInfo.'/uploads/' . $file_name . '.' . $this->file->extension;
    }

    /**
     * @param $file
     * @return mixed
     */
    public static function prepareFileName($file) {
        $file = str_replace(' ', '_', $file); // Replaces all spaces with hyphens.
        return preg_replace('/[^A-Za-z0-9\_]/', '', $file); // Removes special chars.
    }

    /**
     * @param $folder
     * @param $resource_id
     * @param $oldLinks
     */
    public static function removeS3objects($folder, $resource_id, $oldLinks) {
        if (isset($oldLinks['origin']) && isset($oldLinks['thumb'])) {
            $data = [
                ["Key" => $folder . "/origin/" . $resource_id . "/" . pathinfo($oldLinks['origin'])['basename']],
                ["Key" => $folder . "/thumbnails/" . $resource_id . "/" . pathinfo($oldLinks['thumb'])['basename']],
            ];
            Yii::$app->aws->deleteMultipleObjects($data);
        }
    }

    /**
     * @param $resource_id
     * @param $links
     * @return array
     */
    public function removeLocalMealsImages($resource_id, $links)
    {
        $res = [];

        foreach ($links as $item => $link) {
            $fileName = explode('/', $link)[1];
            $fileToLink = $this->origin_folder . '/' . $resource_id . '/' . $fileName;

            // deleting file in 'meals-images/' folder
            try {
                if (unlink($link)) {
                    $res[$item] = $link;
                }
            } catch (\Exception $e) {
            }

            // deleting file in 'meals-images/folder/id/' folder
            try {
                if (unlink($fileToLink)) {
                    $res[$item] = $link;
                }
            } catch (\Exception $e) {
            }
        }

        return $res;
    }

    /**
     * @param $folder
     * @param $resource_id
     * @param null $oldLinks
     * @return array|bool
     */
    public function uploadFileToS3($folder, $resource_id, $oldLinks = null)
    {

        self::removeS3objects($folder, $resource_id, $oldLinks);

        $fileToLink = $this->getFile();

        $fileInfo = pathinfo($fileToLink);
        $basename = $fileInfo['basename'];

        $fileToThumbnail = $this->thumbnail_folder. "/". $basename;
        Image::thumbnail( $fileToLink , $this->ratio_w, $this->ratio_h)
            ->save($fileToThumbnail, ['quality' => 90]);

        // save origin
        $origin_url = Yii::$app->aws->putObject($fileToLink, $folder . "/origin/" . $resource_id . "/");

        // save thumbnail
        $thumbnail_url = Yii::$app->aws->putObject($fileToThumbnail, $folder . "/thumbnails/" . $resource_id . "/");

        if (file_exists($fileToLink)) {
            $this->clearFileByLink($fileToLink);
            $this->clearFileByLink($fileToThumbnail);
            return ["origin" => $origin_url, "thumb" => $thumbnail_url];
        }
        return false;
    }

    /**
     * @return array|bool
     */
    public function uploadFileLocally()
    {

        $fileToLink = $this->getFile();

        $fileInfo = pathinfo($fileToLink);
        $basename = $fileInfo['basename'];

        $fileToThumbnail = $this->thumbnail_folder. "/". $basename;
        Image::thumbnail( $fileToLink , $this->ratio_w, $this->ratio_h)
            ->save($fileToThumbnail, ['quality' => 90]);

        if (file_exists($fileToLink)) {

            return ["origin" => $fileToLink, "thumb" => $fileToThumbnail];
        }
        return false;
    }

}
