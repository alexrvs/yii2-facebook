<?php

namespace alexandervas\facebook;

use Yii;
use yii\base\Component;
use Facebook\FacebookApp;
use Facebook\Helpers\FacebookRedirectLoginHelper;
use Facebook\FacebookRequest;
use Facebook\GraphNodes\GraphUser;
use Exception;
use Facebook\Facebook;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;


class FbComponent extends Component{

    public $appId;

    public $secret;

    public $scope;

    private $session;

    private $accessToken;

    public function init(){

        parent::init();

        $fb_client = new Facebook([
            'app_id' => $this->appId,
            'app_secret' => $this->secret,
            'default_graph_version' => 'v2.2',
        ]);

        $fb_helper = $fb_client->getRedirectLoginHelper();

        try {
            $accessToken = $fb_helper->getAccessToken();

        } catch(FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage();
            exit;
        } catch(FacebookSDKException $e) {

            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage();
            exit;
        }

        if (! isset($accessToken)) {
            if ($fb_helper->getError()) {
                header('HTTP/1.0 401 Unauthorized');
                echo "Error: " . $fb_helper->getError() . "\n";
                echo "Error Code: " . $fb_helper->getErrorCode() . "\n";
                echo "Error Reason: " . $fb_helper->getErrorReason() . "\n";
                echo "Error Description: " . $fb_helper->getErrorDescription() . "\n";
            } else {
                header('HTTP/1.0 400 Bad Request');
                echo 'Bad request';
            }
            exit;
        }

        echo '<h3>Access Token</h3>';
        var_dump($accessToken->getValue());

        $oAuth2Client = $fb_client->getOAuth2Client();
        $tokenMetadata = $oAuth2Client->debugToken($accessToken);
        echo '<h3>Metadata</h3>';
        var_dump($tokenMetadata);
        $tokenMetadata->validateAppId($this->appId);
        $tokenMetadata->validateExpiration();

        if (! $accessToken->isLongLived()) {
            // Exchanges a short-lived access token for a long-lived one
            try {
                $accessToken = $oAuth2Client->getLongLivedAccessToken($accessToken);
            } catch (FacebookSDKException $e) {
                echo "<p>Error getting long-lived access token: " . $fb_helper->getMessage() . "</p>\n\n";
                exit;
            }

            echo '<h3>Long-lived</h3>';
            var_dump($accessToken->getValue());
        }

        $_SESSION['fb_access_token'] = (string) $accessToken;

        try {
            // Returns a `Facebook\FacebookResponse` object
            $response = $fb_client->get('/me?fields=id,name', $accessToken);
        } catch(FacebookResponseException $e) {
            echo 'Graph returned an error: ' . $e->getMessage();
            exit;
        } catch(FacebookSDKException $e) {
            echo 'Facebook SDK returned an error: ' . $e->getMessage();
            exit;
        }
        $user = $response->getGraphUser();

    }


    public function getSession()
    {
        return $this->session;
    }

    public function setSession(FacebookApp  $session)
    {
        $this->setAccessToken($session->getAccessToken());
        $this->session = $session;
    }

    public function getAccessToken()
    {
        return $this->accessToken;
    }

    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        Yii::$app->session->set('fbAccesstoken', $this->accessToken);
    }

    public function getLoginUrl($redirectUrl, $scope = null)
    {
        $helper = new FacebookRedirectLoginHelper($redirectUrl);
        return $helper->getLoginUrl(['scope' => $scope]);
    }

    public function getLoginSession($redirectUrl)
    {
        $helper = new FacebookRedirectLoginHelper($redirectUrl);
        $this->setSession($helper->getSessionFromRedirect());
        return $this->getSession();
    }

    public function getUser($userId = 'me')
    {
        try {
            $request = new FacebookRequest($this->getSession(), 'GET', '/' . $userId);
            return $request->execute()->getGraphObject(GraphUser::className())->asArray();
        } catch (Exception $e) {}

        return [];
    }

    public function getFriends($userId = 'me')
    {
        $limit = 25;
        $friendCount = $this->getFriendsCount($userId);
        $friends = [];

        try {
            for ($offset = 0; $offset <= $friendCount; $offset += $limit) {
                $request = new FacebookRequest($this->getSession(), 'GET', '/' . $userId . '/friends', [
                    'offset' => $offset,
                    'limit' => $limit,
                ]);
                $response = $request->execute()->getGraphObject()->asArray();

                foreach ($response['data'] as $friend) {
                    array_push($friends, (array) $friend);
                }

                if (count($friends) < $limit) {
                    break;
                }
            }
        } catch (Exception $e) {}


        return $friends;
    }

    public function getFriendsCount($userId = 'me')
    {
        try {
            $request = new FacebookRequest($this->getSession(), 'GET', '/' . $userId . '/friends', [
                'offset' => 0,
                'limit' => 0,
            ]);
            $response = $request->execute()->getGraphObject()->asArray();
            return $response['summary']->total_count;
        } catch (Exception $e) {}

        return 0;
    }

}