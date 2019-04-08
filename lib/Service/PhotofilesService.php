<?php

/**
 * Nextcloud - maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Piotr Bator <prbator@gmail.com>
 * @copyright Piotr Bator 2017
 */

namespace OCA\Maps\Service;

use OCP\Files\FileInfo;
use OCP\IL10N;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\ILogger;

use OCA\Maps\DB\Geophoto;
use OCA\Maps\DB\GeophotoMapper;

require_once __DIR__ . '/../../vendor/autoload.php';
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelExif;
use lsolesen\pel\PelTiff;
use lsolesen\pel\PelTag;
use lsolesen\pel\PelEntryAscii;
use lsolesen\pel\PelEntryRational;
use lsolesen\pel\PelIfd;

class PhotofilesService {

    const PHOTO_MIME_TYPES = ['image/jpeg', 'image/tiff'];

    private $l10n;
    private $root;
    private $photoMapper;
    private $logger;

    public function __construct (ILogger $logger, IRootFolder $root, IL10N $l10n, GeophotoMapper $photoMapper) {
        $this->root = $root;
        $this->l10n = $l10n;
        $this->photoMapper = $photoMapper;
        $this->logger = $logger;
    }

    public function rescan ($userId){
        $userFolder = $this->root->getUserFolder($userId);
        $photos = $this->gatherPhotoFiles($userFolder, true);
        $this->photoMapper->deleteAll($userId);
        foreach($photos as $photo) {
            $this->addPhoto($photo, $userId);
        }
    }

    public function addByFile(Node $file) {
        $userFolder = $this->root->getUserFolder($file->getOwner()->getUID());
        if($this->isPhoto($file)) {
            $this->addPhoto($file, $file->getOwner()->getUID());
        }
    }

    public function addByFolder(Node $folder) {
        $photos = $this->gatherPhotoFiles($folder, true);
        foreach($photos as $photo) {
            $this->addPhoto($photo, $folder->getOwner()->getUID());
        }
    }

    public function deleteByFile(Node $file) {
        $this->photoMapper->deleteByFileId($file->getId());
    }

    public function deleteByFolder(Node $folder) {
        $photos = $this->gatherPhotoFiles($folder, true);
        foreach($photos as $photo) {
            $this->photoMapper->deleteByFileId($photo->getId());
        }
    }

    public function setPhotosFilesCoords($userId, $paths, $lat, $lng, $directory) {
        if ($directory === 'true') {
            return $this->setDirectoriesCoords($userId, $paths, $lat, $lng);
        }
        else {
            return $this->setFilesCoords($userId, $paths, $lat, $lng);
        }
    }

    private function setDirectoriesCoords($userId, $paths, $lat, $lng) {
        $userFolder = $this->root->getUserFolder($userId);
        $nbDone = 0;
        foreach ($paths as $dirPath) {
            $cleanDirPath = str_replace(array('../', '..\\'), '',  $dirPath);
            if ($userFolder->nodeExists($cleanDirPath)) {
                $dir = $userFolder->get($cleanDirPath);
                if ($dir->getType() === FileInfo::TYPE_FOLDER) {
                    $nodes = $dir->getDirectoryListing();
                    foreach($nodes as $node) {
                        if ($this->isPhoto($node) and $node->isUpdateable()) {
                            $this->setExifCoords($node, $lat, $lng);
                            // delete and add again
                            $this->deleteByFile($node);
                            $this->addByFile($node);
                            $nbDone++;
                        }
                    }
                }
            }
        }
        return $nbDone;
    }

    private function setFilesCoords($userId, $paths, $lat, $lng) {
        $userFolder = $this->root->getUserFolder($userId);
        $nbDone = 0;
        foreach ($paths as $path) {
            $cleanpath = str_replace(array('../', '..\\'), '',  $path);
            if ($userFolder->nodeExists($cleanpath)) {
                $file = $userFolder->get($cleanpath);
                if ($this->isPhoto($file) and $file->isUpdateable()) {
                    $this->setExifCoords($file, $lat, $lng);
                    // delete and add again
                    $this->deleteByFile($file);
                    $this->addByFile($file);
                    $nbDone++;
                }
            }
        }
        return $nbDone;
    }

    private function addPhoto($photo, $userId) {
        $exif = $this->getExif($photo);
        if (!is_null($exif) AND !is_null($exif->lat)) {
            $photoEntity = new Geophoto();
            $photoEntity->setFileId($photo->getId());
            $photoEntity->setLat($exif->lat);
            $photoEntity->setLng($exif->lng);
            $photoEntity->setUserId($userId);
            // alternative should be file creation date
            $photoEntity->setDateTaken($exif->dateTaken ?? $photo->getMTime());
            $this->photoMapper->insert($photoEntity);
        }
    }

    private function normalizePath($node) {
        return str_replace("files","", $node->getInternalPath());
    }

    public function getPhotosByFolder($userId, $path) {
        $userFolder = $this->root->getUserFolder($userId);
        $folder = $userFolder->get($path);
        return $this->getPhotosListForFolder($folder);
    }

    private function getPhotosListForFolder($folder) {
        $FilesList = $this->gatherPhotoFiles($folder, false);
        $notes = [];
        foreach($FilesList as $File) {
            $file_object = new \stdClass();
            $file_object->fileId = $File->getId();
            $file_object->path = $this->normalizePath($File);
            $notes[] = $file_object;
        }
        return $notes;
    }

    private function gatherPhotoFiles ($folder, $recursive) {
        $notes = [];
        $nodes = $folder->getDirectoryListing();
        foreach($nodes as $node) {
            if($node->getType() === FileInfo::TYPE_FOLDER AND $recursive) {
                $notes = array_merge($notes, $this->gatherPhotoFiles($node, $recursive));
                continue;
            }
            if($this->isPhoto($node)) {
                $notes[] = $node;
            }
        }
        return $notes;
    }

    private function isPhoto($file) {
        if($file->getType() !== \OCP\Files\FileInfo::TYPE_FILE) return false;
        if(!in_array($file->getMimetype(), self::PHOTO_MIME_TYPES)) return false;
        return true;
    }

    private function hasValidExifGeoTags($exif) {
        if (!isset($exif["GPSLatitude"]) OR !isset($exif["GPSLongitude"])) {
            return false;
        }
        if (count($exif["GPSLatitude"]) != 3 OR count($exif["GPSLongitude"]) != 3) {
            return false;
        }
        //Check photos are on the earth
        if ($exif["GPSLatitude"][0]>=90 OR $exif["GPSLongitude"][0]>=180) {
            return false;
        }
        //Check photos are not on NULL island, remove if they should be.
        if($exif["GPSLatitude"][0]==0 AND $exif["GPSLatitude"][1]==0 AND $exif["GPSLongitude"][0]==0 AND $exif["GPSLongitude"][1]==0){
            return false;
        }
        return true;
    }

    private function getExif($file) {
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        $exif = @exif_read_data($path);
        if($this->hasValidExifGeoTags($exif)){
            //Check if there is exif infor
            $LatM = 1; $LongM = 1;
            if($exif["GPSLatitudeRef"] == 'S'){
                $LatM = -1;
            }
            if($exif["GPSLongitudeRef"] == 'W'){
                $LongM = -1;
            }
            //get the GPS data
            $gps['LatDegree']=$exif["GPSLatitude"][0];
            $gps['LatMinute']=$exif["GPSLatitude"][1];
            $gps['LatgSeconds']=$exif["GPSLatitude"][2];
            $gps['LongDegree']=$exif["GPSLongitude"][0];
            $gps['LongMinute']=$exif["GPSLongitude"][1];
            $gps['LongSeconds']=$exif["GPSLongitude"][2];

            //convert strings to numbers
            foreach($gps as $key => $value){
                $pos = strpos($value, '/');
                if($pos !== false){
                    $temp = explode('/',$value);
                    $gps[$key] = $temp[0] / $temp[1];
                }
            }
            $file_object = new \stdClass();
            //calculate the decimal degree
            $file_object->lat = $LatM * ($gps['LatDegree'] + ($gps['LatMinute'] / 60) + ($gps['LatgSeconds'] / 3600));
            $file_object->lng = $LongM * ($gps['LongDegree'] + ($gps['LongMinute'] / 60) + ($gps['LongSeconds'] / 3600));
            if (isset($exif["DateTimeOriginal"])) {
                $file_object->dateTaken = strtotime($exif["DateTimeOriginal"]);
            }
            return $file_object;
        }
        return null;
    }

    private function setExifCoords($file, $lat, $lng) {
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());

        $pelJpeg = new PelJpeg($path);

        $pelExif = $pelJpeg->getExif();
        if ($pelExif == null) {
            $pelExif = new PelExif();
            $pelJpeg->setExif($pelExif);
        }

        $pelTiff = $pelExif->getTiff();
        if ($pelTiff == null) {
            $pelTiff = new PelTiff();
            $pelExif->setTiff($pelTiff);
        }

        $pelIfd0 = $pelTiff->getIfd();
        if ($pelIfd0 == null) {
            $pelIfd0 = new PelIfd(PelIfd::IFD0);
            $pelTiff->setIfd($pelIfd0);
        }

        $pelSubIfdGps = new PelIfd(PelIfd::GPS);
        $pelIfd0->addSubIfd($pelSubIfdGps);

        $this->setGeolocation($pelSubIfdGps, $lat, $lng);

        $pelJpeg->saveFile($path);
        $file->touch();
    }

    private function setGeolocation($pelSubIfdGps, $latitudeDegreeDecimal, $longitudeDegreeDecimal) {
        $latitudeRef = ($latitudeDegreeDecimal >= 0) ? 'N' : 'S';
        $latitudeDegreeMinuteSecond
            = $this->degreeDecimalToDegreeMinuteSecond(abs($latitudeDegreeDecimal));
        $longitudeRef= ($longitudeDegreeDecimal >= 0) ? 'E' : 'W';
        $longitudeDegreeMinuteSecond
            = $this->degreeDecimalToDegreeMinuteSecond(abs($longitudeDegreeDecimal));

        $pelSubIfdGps->addEntry(new PelEntryAscii(
            PelTag::GPS_LATITUDE_REF, $latitudeRef));
        $pelSubIfdGps->addEntry(new PelEntryRational(
            PelTag::GPS_LATITUDE,
            array($latitudeDegreeMinuteSecond['degree'], 1),
            array($latitudeDegreeMinuteSecond['minute'], 1),
            array(round($latitudeDegreeMinuteSecond['second'] * 1000), 1000)));
        $pelSubIfdGps->addEntry(new PelEntryAscii(
            PelTag::GPS_LONGITUDE_REF, $longitudeRef));
        $pelSubIfdGps->addEntry(new PelEntryRational(
            PelTag::GPS_LONGITUDE,
            array($longitudeDegreeMinuteSecond['degree'], 1),
            array($longitudeDegreeMinuteSecond['minute'], 1),
            array(round($longitudeDegreeMinuteSecond['second'] * 1000), 1000)));
    }

    private function degreeDecimalToDegreeMinuteSecond($degreeDecimal) {
        $degree = floor($degreeDecimal);
        $remainder = $degreeDecimal - $degree;
        $minute = floor($remainder * 60);
        $remainder = ($remainder * 60) - $minute;
        $second = $remainder * 60;
        return array('degree' => $degree, 'minute' => $minute, 'second' => $second);
    }

}
