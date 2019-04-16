<?php
/**
 * Nextcloud - Maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Maps\Controller;

use OCP\App\IAppManager;

use OCP\IURLGenerator;
use OCP\IConfig;
use \OCP\IL10N;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\ApiController;
use OCP\Constants;
use OCP\Share;

use OCP\IDateTimeZone;

use OCA\Maps\Service\DevicesService;

function endswith($string, $test) {
    $strlen = strlen($string);
    $testlen = strlen($test);
    if ($testlen > $strlen) return false;
    return substr_compare($string, $test, $strlen - $testlen, $testlen) === 0;
}

class DevicesController extends Controller {

    private $userId;
    private $userfolder;
    private $config;
    private $appVersion;
    private $shareManager;
    private $userManager;
    private $groupManager;
    private $dbconnection;
    private $dbtype;
    private $dbdblquotes;
    private $defaultDeviceId;
    private $trans;
    private $logger;
    private $devicesService;
    private $dateTimeZone;
    protected $appName;

    public function __construct($AppName, IRequest $request, $UserId,
                                $userfolder, $config, $shareManager,
                                IAppManager $appManager, $userManager,
                                $groupManager, IL10N $trans, $logger, DevicesService $devicesService,
                                IDateTimeZone $dateTimeZone){
        parent::__construct($AppName, $request);
        $this->devicesService = $devicesService;
        $this->dateTimeZone = $dateTimeZone;
        $this->logger = $logger;
        $this->appName = $AppName;
        $this->appVersion = $config->getAppValue('maps', 'installed_version');
        $this->userId = $UserId;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->trans = $trans;
        $this->dbtype = $config->getSystemValue('dbtype');
        // IConfig object
        $this->config = $config;
        $this->dbconnection = \OC::$server->getDatabaseConnection();
        if ($UserId !== '' and $userfolder !== null){
            // path of user files folder relative to DATA folder
            $this->userfolder = $userfolder;
        }
        $this->shareManager = $shareManager;
    }

    /**
     * @NoAdminRequired
     */
    public function exportDevices($deviceIdList=null, $begin, $end, $all=false) {
        // sorry about ugly deviceIdList management:
        // when an empty list is passed in http request, we get null here
        if ($deviceIdList === null or (is_array($deviceIdList) and count($deviceIdList) === 0)) {
            $response = new DataResponse('Nothing to export', 400);
            return $response;
        }

        // create /Maps directory if necessary
        $userFolder = $this->userfolder;
        if (!$userFolder->nodeExists('/Maps')) {
            $userFolder->newFolder('Maps');
        }
        if ($userFolder->nodeExists('/Maps')) {
            $mapsFolder = $userFolder->get('/Maps');
            if ($mapsFolder->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                $response = new DataResponse('/Maps is not a directory', 400);
                return $response;
            }
            else if (!$mapsFolder->isCreatable()) {
                $response = new DataResponse('/Maps is not writeable', 400);
                return $response;
            }
        }
        else {
            $response = new DataResponse('Impossible to create /Maps', 400);
            return $response;
        }

        $nbDevices = $this->devicesService->countDevices($this->userId, $deviceIdList, $begin, $end);
        if ($nbDevices === 0) {
            $response = new DataResponse('Nothing to export', 400);
            return $response;
        }

        // generate export file name
        $prefix = $all ? '' : 'filtered-';
        $tz = $this->dateTimeZone->getTimeZone();
        $now = new \DateTime('now', $tz);
        $dateStr = $now->format('Y-m-d H:i:s (P)');
        $filename = $dateStr.' '.$prefix.'devices.gpx';

        if ($mapsFolder->nodeExists($filename)) {
            $mapsFolder->get($filename)->delete();
        }
        $file = $mapsFolder->newFile($filename);
        $handler = $file->fopen('w');

        $this->devicesService->exportDevices($this->userId, $handler, $deviceIdList, $begin, $end, $this->appVersion);

        fclose($handler);
        $file->touch();
        return new DataResponse('/Maps/'.$filename);
    }

}