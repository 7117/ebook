<?php
namespace app\common\services;

use app\models\log\AppAccessLog;
use Yii;
use app\models\log\AppLog;
use app\common\services\UtilService;

class AppLogService {

    public static function addErrorLog($appname,$content)
    {
        $error = Yii::$app->errorHandler->exception;

        $model_app_log = new AppLog();

        $model_app_log->app_name = $appname;
        $model_app_log->content = $content;
        $model_app_log->ip = UtilService::getIP();
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $model_app_log->ua = $_SERVER['HTTP_USER_AGENT'];
        }
        if ($error) {
            $model_app_log->err_code = $error->getCode();
            if (isset($error->statusCode)) {
                $model_app_log->http_code = $error->statusCode;
            }
            if (method_exists($error, "getName")) {
                $model_app_log->err_name = $error->getName();
            }
        }

        $model_app_log->created_time = date("Y-m-d H:i:s");
        $model_app_log->save();

    }

    public static function addAppAccessLog( $uid = 0 ) {
        $get = Yii::$app->request->get();
        $post = Yii::$app->request->post();

        $target_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : "";
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : "";
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "";

        $access_log = new AppAccessLog();

        $access_log->uid = $uid;
        $access_log->target_url = $target_url;
        $access_log->query_params = json_encode(array_merge($get,$post));
        $access_log->ua = $ua;
        $access_log->ip = UtilService::getIP();
        $access_log->created_time = date("Y-m-d H:i:s");

        return $access_log->save();

    }
}