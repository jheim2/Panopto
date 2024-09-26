<?php
declare(strict_types=1);
/**
 * This file is part of the Panopto Repository Object plugin for ILIAS.
 * This plugin allows users to embed Panopto videos in ILIAS as repository objects.
 *
 * The Panopto Repository Object plugin for ILIAS is open-source and licensed under GPL-3.0.
 * For license details, visit https://www.gnu.org/licenses/gpl-3.0.en.html.
 *
 * To report bugs or participate in discussions, visit the Mantis system and filter by
 * the category "Panopto" at https://mantis.ilias.de.
 *
 * More information and source code are available at:
 * https://github.com/surlabs/Panopto
 *
 * If you need support, please contact the maintainer of this software at:
 * info@surlabs.es
 *
 */

namespace connection;
//require_once __DIR__."/../../vendor/autoload.php";

use ilException;
use Panopto\AccessManagement\AccessManagement;
use Panopto\AccessManagement\ArrayOfguid;
use Panopto\AccessManagement\GetSessionAccessDetails;
use Panopto\AccessManagement\GetUserAccessDetails;
use Panopto\AccessManagement\GrantUsersAccessToFolder;
use Panopto\AccessManagement\GrantUsersViewerAccessToSession;
use Panopto\AccessManagement\SessionAccessDetails;
use Panopto\AccessManagement\UserAccessDetails;
use Panopto\AccessManagement\AuthenticationInfo;
use Panopto\Client as PanoptoClientAPI;
use Panopto\SessionManagement\ArrayOfSessionState;
use Panopto\SessionManagement\GetAllFoldersByExternalId;
use Panopto\SessionManagement\GetSessionsList;
use Panopto\SessionManagement\ListSessionsRequest;
use Panopto\SessionManagement\SessionManagement;
use Panopto\SessionManagement\SessionState;
use Panopto\SessionManagement\Pagination;
use Panopto\SessionManagement\ArrayOfstring;
use Panopto\UserManagement\CreateUser;
use Panopto\UserManagement\GetUserByKey;
use Panopto\UserManagement\User;
use Panopto\UserManagement\UserManagement;
use platform\PanoptoDatabase;
use platform\SorterEntry;
use utils\DTO\ContentObjectBuilder;
use Exception;
use platform\PanoptoConfig;
use platform\PanoptoException;
use Panopto\SessionManagement\Folder;
use utils\PanoptoUtils;


/**
 * Class PanoptoClient
 * @authors Jesús Copado, Daniel Cazalla, Saúl Díaz, Juan Aguilar <info@surlabs.es>
 */
class PanoptoClient
{
    /**
     * @var self
     */
    protected static PanoptoClient $instance;


    /**
     * @return self
     */
    public static function getInstance(): PanoptoClient
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }


    /**
     * @var PanoptoClientAPI
     */
    protected PanoptoClientAPI $panoptoclient;
    /**
     * @var AuthenticationInfo
     */
    protected AuthenticationInfo $auth;
    /**
     * @var PanoptoRestClient
     */
    protected PanoptoRestClient $rest_client;
    /**
     * @var PanoptoLog
     */
    protected PanoptoLog $log;

    /**
     * xpanClient constructor.
     * @throws PanoptoException
     */
    public function __construct()
    {
        $this->log = PanoptoLog::getInstance();

        $arrContextOptions = array("ssl" => array("verify_peer" => false, "verify_peer_name" => false));
        $this->panoptoclient = new PanoptoClientAPI(PanoptoConfig::get('hostname'), array('trace' => 1, 'stream_context' => stream_context_e($arrContextOptions)));
        $this->panoptoclient->setAuthenticationInfo(PanoptoConfig::get('instance_name') . "\\" . PanoptoConfig::get('api_user'), '', PanoptoConfig::get('application_key'));
        $this->auth = new AuthenticationInfo();
        $this->auth->setUserKey(PanoptoConfig::get('instance_name') . "\\" . PanoptoConfig::get('api_user'));
        $this->auth->setPassword(null);
        $this->auth->setAuthCode($this->panoptoclient->getAuthenticationInfo()->getAuthCode());
        $this->rest_client = PanoptoRestClient::getInstance();

    }

    /**
     * @throws PanoptoException
     * @throws ilException
     * @throws Exception
     */
    public function getContentObjectsOfFolder($folder_id, $page_limit = false, $page = 0, int $ref_id = 0): array
    {
        $perpage = 10;
        $request = new ListSessionsRequest();
        $request->setFolderId($folder_id);

        $pagination = new Pagination();
        $pagination->setMaxNumberResults(999);
        $pagination->setPageNumber(0);
        $request->setPagination($pagination);

        $states = new ArrayOfSessionState();
        $states->setSessionState(array(SessionState::Complete, SessionState::Broadcasting, SessionState::Scheduled));
        $request->setStates($states);

        $this->log->write('*********');
        $this->log->write('SOAP call "GetSessionsList"');
        $this->log->write("request:");
        $this->log->write(print_r($request, true));

        $params = new GetSessionsList(
            $this->auth,
            $request,
            ''
        );

        /** @var SessionManagement $session_client */
        $session_client = $this->panoptoclient->SessionManagement();
        try {
            $sessions_result = $session_client->GetSessionsList($params);
        } catch (Exception $e) {
            $this->log->logError($e->getCode(), $e->getMessage());
            throw $e;
        }

        $sessions = $sessions_result->getGetSessionsListResult();

        $this->log->write('Status: ' . substr($session_client->__last_response_headers, 0, strpos($session_client->__last_response_headers, "\r\n")));
        $this->log->write('Received ' . $sessions->getTotalNumberResults() . ' object(s).');


        $sessions = ContentObjectBuilder::buildSessionsDTOsFromSessions($sessions->getResults()->getSession() ?? []);
        $playlists = $this->rest_client->getPlaylistsOfFolder($folder_id);
        $objects = array_merge($sessions, $playlists);
        $objects = SorterEntry::generateSortedObjects($objects, $ref_id);
        if ($page_limit) {
            // Implement manual pagination
            return array(
                "count" => count($objects),
                "objects" => array_slice($objects, $page * $perpage, $perpage),
            );
        } else {
            return $objects;
        }

    }

    /**
     * @throws Exception
     */
    public function getFolderByExternalId(int $ext_id): ?Folder
    {
        $folders = $this->getAllFoldersByExternalId((array)$ext_id);
        return array_shift($folders);
    }

    /**
     * @throws PanoptoException
     * @throws Exception
     */
    public function getAllFoldersByExternalId(array $ext_ids): ?array
    {
        $this->log->write('*********');
        $this->log->write('SOAP call "GetAllFoldersByExternalId"');
        $this->log->write("folderExternalIds:");
        $this->log->write(print_r($ext_ids, true));
        $this->log->write("providerNames:");
        $this->log->write(print_r(array(PanoptoConfig::get("instance_name")), true));

        $instanceArray = new ArrayOfstring();
        $instanceArray->setString(array(PanoptoConfig::get('instance_name')));


        $params = new GetAllFoldersByExternalId(
            $this->auth,
            $ext_ids,
            $instanceArray
        );


        $session_client = $this->panoptoclient->SessionManagement();

        $return = $session_client->GetAllFoldersByExternalId($params)->getGetAllFoldersByExternalIdResult()->getFolder();


        $this->log->write('Status: ' . substr($session_client->__last_response_headers, 0, strpos($session_client->__last_response_headers, "\r\n")));
        $this->log->write('Received ' . (isset($return) ? count($return) : 0) . ' object(s).');
        return is_array($return) ? $return : array();
    }

    /**
     * @throws Exception
     */
    public function synchronizeorPermissions($user_id = 0): void
    {
        $xpanDb = new PanoptoDatabase();
        $result = $xpanDb->select("xpan_objects", null, ["folder_ext_id"]);

        $folder_ext_ids = array();

        foreach ($result as $row) {
            $folder_ext_ids[] = $row["folder_ext_id"];
        }

        if (!empty($folder_ext_ids)) {
            $typedFolders = [];
            $typedFolders = array_unique($folder_ext_ids);
            $folders = $this->getAllFoldersByExternalId($typedFolders);
            foreach ($folders as $folder) {
                if ($folder && ($this->getUserAccessOnFolder($folder->getId(), $user_id) !== 'Creator')) {
                    $this->grantUserAccessToFolder($folder->getId(), 'Creator', $user_id);
                }
            }
        }
    }

    /**
     * @param $folder_id
     * @param int $user_id
     * @return bool|string Creator, Viewer or false
     * @throws Exception
     */
    public function getUserAccessOnFolder($folder_id, int $user_id = 0)
    {
        $user_details = $this->getUserAccessDetails($user_id);
        $user_groups_details = $user_details->getGroupMembershipAccess()->getGroupAccessDetails();
        $user_groups_details = is_array($user_groups_details) ? $user_groups_details : array();

        // fetch creator access folders from groups
        $folders_with_creator_access = array();
        foreach ($user_groups_details as $user_group_details) {
            $folder_ids = $user_group_details->getFoldersWithCreatorAccess()->getGuid();
            if (is_array($folder_ids)) {
                $folders_with_creator_access = array_merge($folders_with_creator_access, $folder_ids);
            }
        }
        $folder_ids = $user_details->getFoldersWithCreatorAccess()->getGuid();
        $folders_with_creator_access = is_array($folder_ids) ? array_merge($folders_with_creator_access, $folder_ids) : $folders_with_creator_access;

        if (in_array($folder_id, $folders_with_creator_access)) {
            return 'Creator';
        }


        // fetch viewer access folders from groups
        $folders_with_viewer_access = array();
        foreach ($user_groups_details as $user_group_details) {
            $folder_ids = $user_group_details->getFoldersWithViewerAccess()->getGuid();
            if (is_array($folder_ids)) {
                $folders_with_viewer_access = array_merge($folders_with_viewer_access, $folder_ids);
            }
        }
        $folder_ids = $user_details->getFoldersWithViewerAccess()->getGuid();
        $folders_with_viewer_access = is_array($folder_ids) ? array_merge($folders_with_viewer_access, $folder_ids) : $folders_with_viewer_access;

        if (in_array($folder_id, $folders_with_viewer_access)) {
            return 'Viewer';
        }

        return false;
    }

    /**
     * @param $user_id
     * @return UserAccessDetails
     * @throws Exception
     */
    public function getUserAccessDetails($user_id = 0): UserAccessDetails
    {
        static $user_access_details;
        global $DIC;
        $user_id = $user_id ? $user_id : $DIC->user()->getId();
        if (!isset($user_access_details[$user_id])) {
            $guid = $this->getUserGuid($user_id);
            $this->log->write('*********');
            $this->log->write('SOAP call "GetUserAccessDetails"');
            $this->log->write("userId:");
            $this->log->write(print_r($guid, true));

            $params = new GetUserAccessDetails(
                $this->auth,
                $guid
            );

            /** @var AccessManagement $access_management */
            $access_management = $this->panoptoclient->AccessManagement();
            try {
                $user_access_details[$user_id] = $access_management->GetUserAccessDetails($params)->getGetUserAccessDetailsResult();
            } catch (Exception $e) {
                $this->log->logError($e->getCode(), $e->getMessage());
                throw $e;
            }


            $this->log->write('Status: ' . substr($access_management->__last_response_headers, 0, strpos($access_management->__last_response_headers, "\r\n")));
            $this->log->write('Received ' . (is_array($user_access_details[$user_id]) ? (int)count($user_access_details[$user_id]) : 0) . ' object(s).');
        }
        return $user_access_details[$user_id];
    }

    /**
     * @param int $user_id
     * @return String
     * @throws Exception
     */
    public function getUserGuid(int $user_id = 0): string
    {
        static $user_guids;
        if (!isset($user_guids[$user_id])) {
            global $DIC;
            $user_id = $user_id ? $user_id : $DIC->user()->getId();
            $user_guids[$user_id] = $this->getUserByKey(PanoptoUtils::getUserKey($user_id))->getUserId();
        }
        return $user_guids[$user_id];
    }

    /**
     * @param string $user_key
     * @return User
     * @throws Exception
     */
    public function getUserByKey(string $user_key = ''): User
    {
        $user_key = $user_key ? $user_key : PanoptoUtils::getUserKey();

        $this->log->write('*********');
        $this->log->write('SOAP call "getUserByKey"');
        $this->log->write("userKey:");
        $this->log->write(print_r($user_key, true));

        /** @var UserManagement $user_management */
        $user_management = $this->panoptoclient->UserManagement();

        $newAuth = new \Panopto\UserManagement\AuthenticationInfo();
        $newAuth->setUserKey(PanoptoConfig::get('instance_name') . "\\" . PanoptoConfig::get('api_user'));
        $newAuth->setPassword(null);
        $newAuth->setAuthCode($this->panoptoclient->getAuthenticationInfo()->getAuthCode());

        $params = new GetUserByKey(
            $newAuth,
            $user_key
        );

        try {
            $return = $user_management->GetUserByKey($params)->getGetUserByKeyResult();
        } catch (Exception $e) {
            $this->log->logError($e->getCode(), $e->getMessage());
            throw $e;
        }

        if ($return->getUserId() == '00000000-0000-0000-0000-000000000000') {
            $this->log->write('Status: User Not Found');
            $this->createUser($user_key, $newAuth);

            try {
                $this->log->write('*********');
                $this->log->write('SOAP call "getUserByKey"');
                $this->log->write("userKey:");
                $this->log->write(print_r($user_key, true));
                $return = $user_management->GetUserByKey($params)->getGetUserByKeyResult();
            } catch (Exception $e) {
                $this->log->logError($e->getCode(), $e->getMessage());
                throw $e;
            }
        }
        $this->log->write('Status: ' . substr($user_management->__last_response_headers, 0, strpos($user_management->__last_response_headers, "\r\n")));
        $this->log->write('Found user with id: ' . $return->getUserId());

        return $return;
    }

    /**
     * @param $user_key
     * @param $auth
     * @throws Exception
     */
    public function createUser($user_key, $auth): void
    {
        global $DIC;
        $this->log->write('*********');
        $this->log->write('SOAP call "createUser"');
        $this->log->write("userKey:");
        $this->log->write(print_r($user_key, true));

        $user = new User();
        $user->setFirstName($DIC->user()->getFirstname());
        $user->setLastName($DIC->user()->getLastname());
        $user->setEmail($DIC->user()->getEmail());
        $user->setUserKey($user_key);

        $params = new CreateUser(
            $auth,
            $user,
            ''
        );

        /** @var UserManagement $user_management */
        $user_management = $this->panoptoclient->UserManagement();
        try {
            $user_management->CreateUser($params);
        } catch (Exception $e) {
            $this->log->logError($e->getCode(), $e->getMessage());
            throw $e;
        }

        $this->log->write('Status: ' . substr($user_management->__last_response_headers, 0, strpos($user_management->__last_response_headers, "\r\n")));
    }

    /**
     * Grant single user access to folder. For current user, leave $user_id = 0
     *
     * @param $folder_id
     * @param $role
     * @param int $user_id
     * @throws Exception
     */
    public function grantUserAccessToFolder($folder_id, $role, $user_id = 0): void
    {
        $this->grantUsersAccessToFolder(array($user_id), $folder_id, $role);
    }

    /**
     * Grant multiple users access to folder.
     *
     * @param array $user_ids
     * @param $folder_id
     * @param $role
     * @throws Exception
     */
    public function grantUsersAccessToFolder(array $user_ids, $folder_id, $role): void
    {
        $guids = array();
        foreach ($user_ids as $user_id) {
            $guids[] = $this->getUserGuid($user_id);
        }

        $this->log->write('*********');
        $this->log->write('SOAP call "GrantUsersAccessToFolder"');
        $this->log->write("folderId:");
        $this->log->write(print_r($folder_id, true));
        $this->log->write("userIds:");
        $this->log->write(print_r($guids, true));
        $this->log->write("role:");
        $this->log->write(print_r($role, true));
        $arrayOfGuids = new ArrayOfguid();
        $arrayOfGuids->setGuid($guids);

        $params = new GrantUsersAccessToFolder(
            $this->auth,
            $folder_id,
            $arrayOfGuids,
            $role
        );

        /** @var AccessManagement $access_management */
        $access_management = $this->panoptoclient->AccessManagement();
        try {
            $access_management->GrantUsersAccessToFolder($params);
        } catch (Exception $e) {
            $this->log->logError($e->getCode(), $e->getMessage());
            throw $e;
        }

        $this->log->write('Status: ' . substr($access_management->__last_response_headers, 0, strpos($access_management->__last_response_headers, "\r\n")));
    }

    /**
     * @param string $playlist_id
     * @param int $user_id
     * @throws ilException|Exception
     */
    public function grantViewerAccessToPlaylistFolder(string $playlist_id, $user_id = 0): void
    {
        $folder_id = $this->getFolderIdOfPlaylist($playlist_id);
        if (!in_array($this->getUserAccessOnFolder($folder_id, $user_id), ['Viewer', 'Creator', 'Publisher'])) {
            $this->grantUserAccessToFolder($folder_id, 'Viewer', $user_id);
        }
    }

    /**
     * @param string $playlist_id
     * @return string
     * @throws ilException
     */
    public function getFolderIdOfPlaylist(string $playlist_id): string
    {
        return $this->rest_client->getFolderIdOfPlaylist($playlist_id);
    }

    /**
     * @param string $session_id
     * @param int $user_id
     * @throws Exception
     */
    public function grantViewerAccessToSession(string $session_id, $user_id = 0): void
    {
        $this->grantUserViewerAccessToSession($session_id, $user_id);

        if (!$this->hasUserViewerAccessOnSession($session_id, $user_id)) {
            $this->grantUserViewerAccessToSession($session_id, $user_id);
        }
    }

    /**
     * @param $session_id
     * @param int $user_id
     * @return bool
     * @throws Exception
     */
    public function hasUserViewerAccessOnSession($session_id, $user_id = 0): bool
    {
        $user_details = $this->getUserAccessDetails($user_id);
        $session_details = $this->getSessionAccessDetails($session_id);
        $folder_details = $session_details->getFolderAccess();

        $sessions_with_viewer_access = $user_details->getSessionsWithViewerAccess()->getGuid();
        $sessions_with_viewer_access = is_array($sessions_with_viewer_access) ? $sessions_with_viewer_access : array();

        $user_groups_details = $user_details->getGroupMembershipAccess()->getGroupAccessDetails();
        $user_groups_details = is_array($user_groups_details) ? $user_groups_details : array();
        foreach ($user_groups_details as $user_group_details) {
            $session_ids = $user_group_details->getSessionsWithViewerAccess();
            if (is_array($session_ids)) {
                $sessions_with_viewer_access = array_merge($sessions_with_viewer_access, $session_ids);
            }
        }

        if (
            $this->hasUserViewerAccessOnFolder($folder_details->getFolderId(), $user_id)
            || in_array($session_id, $sessions_with_viewer_access)
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param $folder_id
     * @param int $user_id
     * @return bool
     * @throws Exception
     */
    public function hasUserViewerAccessOnFolder($folder_id, int $user_id = 0): bool
    {
        return in_array($this->getUserAccessOnFolder($folder_id, $user_id), array('Viewer', 'Creator', 'Viewer'));
    }

    /**
     *
     * Grant single user viewer access to session. For current user, leave $user_id = 0
     *
     * @param $session_id
     * @param int $user_id
     * @throws Exception
     */
    public function grantUserViewerAccessToSession($session_id, $user_id = 0): void
    {
        $this->grantUsersViewerAccessToSession(array($user_id), $session_id);
    }

    /**
     * Grant multiple users viewer access to session.
     *
     * @param array $user_ids
     * @param $session_id
     * @throws Exception
     */
    public function grantUsersViewerAccessToSession(array $user_ids, $session_id): void
    {
        $guids = array();
        foreach ($user_ids as $user_id) {
            $guids[] = $this->getUserGuid($user_id);
        }

        $this->log->write('*********');
        $this->log->write('SOAP call "GrantUsersViewerAccessToSession"');
        $this->log->write("sessionId:");
        $this->log->write(print_r($session_id, true));
        $this->log->write("userIds:");
        $this->log->write(print_r($guids, true));

        $arrayGuids = new ArrayOfguid();
        $arrayGuids->setGuid($guids);

        $params = new GrantUsersViewerAccessToSession(
            $this->auth,
            $session_id,
            $arrayGuids
        );

        /** @var AccessManagement $access_management */
        $access_management = $this->panoptoclient->AccessManagement();
        try {
            $access_management->GrantUsersViewerAccessToSession($params);
        } catch (Exception $e) {
            $this->log->logError($e->getCode(), $e->getMessage());
            throw $e;
        }

        $this->log->write('Status: ' . substr($access_management->__last_response_headers, 0, strpos($access_management->__last_response_headers, "\r\n")));

    }

    /**
     * @param $session_id
     * @return SessionAccessDetails
     * @throws Exception
     */
    public function getSessionAccessDetails($session_id): SessionAccessDetails
    {
        static $session_access_details;
        if (!isset($session_access_details[$session_id])) {
            $this->log->write('*********');
            $this->log->write('SOAP call "GetSessionAccessDetails"');
            $this->log->write("sessionId:");
            $this->log->write(print_r($session_id, true));

            $params = new GetSessionAccessDetails(
                $this->auth,
                $session_id
            );

            /** @var AccessManagement $access_management */
            $access_management = $this->panoptoclient->AccessManagement();
            try {
                $session_access_details[$session_id] = $access_management->GetSessionAccessDetails($params)->getGetSessionAccessDetailsResult();
            } catch (Exception $e) {
                $this->log->logError($e->getCode(), $e->getMessage());
                throw $e;
            }

            $this->log->write('Status: ' . substr($access_management->__last_response_headers, 0, strpos($access_management->__last_response_headers, "\r\n")));
            $this->log->write('Received ' .
                (is_array($session_access_details[$session_id]) ? (int)count($session_access_details[$session_id]) : 0) .
                ' object(s).'
            );
        }
        return $session_access_details[$session_id];
    }

}
