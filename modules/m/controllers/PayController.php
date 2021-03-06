<?php

namespace app\modules\m\controllers;

use app\common\services\ConstantMapService;
use app\common\services\PayOrderService;
use app\common\services\UrlService;
use app\common\services\UtilService;
use app\common\services\weixin\PayApiService;
use app\models\member\OauthMemberBind;
use app\models\pay\PayOrder;
use app\modules\m\controllers\common\BaseController;
use yii\log\FileTarget;

class PayController extends BaseController
{
    //展示
    public function actionBuy(){

        $pay_order_id = intval( $this->get("pay_order_id",0) );
        $reback_url = UrlService::buildMUrl("/user/index");
        if( !$pay_order_id ){
            return $this->redirect( $reback_url );
        }

        $pay_order_info = PayOrder::find()->where([ 'member_id' => $this->current_user['id'],'id' => $pay_order_id,'status' => -8 ])->one();
        if( !$pay_order_info ){
            return $this->redirect( $reback_url );
        }

        return $this->render('buy',[
            'pay_order_info' => $pay_order_info
        ]);

    }

    //预支付
    public function actionPrepare(){
        $pay_order_id = intval( $this->post("pay_order_id",0) );
        if( !$pay_order_id ){
            return $this->renderJSON( [],"系统繁忙，请稍后再试",-1 );
        }

        if( !UtilService::isWechat() ) {
            return $this->renderJSON([],"仅支持微信支付，请将页面链接粘贴至微信打开",-1);
        }

        $pay_order_info = PayOrder::find()->where([ 'member_id' => $this->current_user['id'],'id' => $pay_order_id,'status' => -8 ])->one();
        if( !$pay_order_info ){
            return $this->renderJSON( [],ConstantMapService::$default_syserror,-1 );
        }

        $openid = $this->getOpenId();
        if( !$openid  ){
            $err_msg = "购买卡前请绑微信";
            return $this->renderJSON([],$err_msg,-1);
        }

        $config_weixin = \Yii::$app->params['weixin'];
        $wx_target = new PayApiService( $config_weixin );

        $notify_url = $config_weixin['pay']['notify_url']['m'];

        //设置必须参数
        $wx_target->setParameter("appid",$config_weixin['appid']);
        $wx_target->setParameter("mch_id",$config_weixin['pay']['mch_id']);
        $wx_target->setParameter("openid",$openid);
        $wx_target->setParameter("body",$pay_order_info['note']);
        $wx_target->setParameter("out_trade_no",$pay_order_info['order_sn'] );
        $wx_target->setParameter("total_fee",$pay_order_info['pay_price'] * 100 );
        $wx_target->setParameter("notify_url",UrlService::buildMUrl( $notify_url ) );
        $wx_target->setParameter("trade_type","JSAPI");

        //获取返回信息
        $prepayInfo = $wx_target->getPrepayInfo();
        if(!$prepayInfo){
            return false;
        }

        $wx_target->setPrepayId($prepayInfo['prepay_id']);
        return $this->renderJSON( $wx_target->getParameters() );
    }

    //微信服务器连接处
    public function actionCallback(){

        if ( !\Yii::$app->request->isPost ) {
            return $this->payEcho(false);
        }

        $check_ret = $this->OrderCallbackCheck();

        if( !$check_ret ){
            return $this->payEcho(false);
        }
        $client_type = $check_ret['client_type'];

        $order_sn = $check_ret['order_sn'];

        $pay_order_info = PayOrder::findOne([ 'order_sn' => $order_sn]);
        if (!$pay_order_info) {
            return $this->payEcho(false,$client_type );
        }

        if ( $client_type == "wechat"  &&  intval( strval( $pay_order_info['pay_price'] * 100 ) ) != $check_ret['total_fee']) { //微信单位为分
            return $this->payEcho(false,$client_type );
        }

        if ($pay_order_info['status'] == 1) {
            return $this->payEcho(true,$client_type );
        }

        $params = [
            'pay_sn' => $check_ret['transaction_id'],
            'callback_data' => ''
        ];

        PayOrderService::orderSuccess($pay_order_info['id'],$params);

        //记录支付回调信息
        PayOrderService::setPayOrderCallbackData($pay_order_info['id'],'pay',$check_ret['callback_data']);

        return self::payEcho(true,$client_type);
    }

    //处理微信返回的参数
    protected function OrderCallbackCheck(){

        $xml = file_get_contents("php://input");
        $config_weixin = \Yii::$app->params['weixin'];
        $wx_target = new PayApiService( $config_weixin );
        $wx_ret = $wx_target->xmlToArray($xml);
        $this->recordCallback( $xml );
        if(!$wx_ret){
            return false;
        }

        if($wx_ret['return_code'] == "FAIL" || $wx_ret['result_code'] == "FAIL"){
            return false;
        }

        if( !isset($wx_ret['sign']) ){
            return false;
        }

        $order_sn = $wx_ret['out_trade_no'];
        foreach(  $wx_ret as $_key => $_val ){
            if( in_array( $_key,[ 'sign' ] ) ){
                continue;
            }
            $wx_target->setParameter( $_key,$_val );
        }

        if( !$wx_target->checkSign( $wx_ret['sign'] ) ){
            return false;
        }

        return [
            'result_code' => 'SUCCESS',
            'client_type' => "wechat",
            'order_sn' => $order_sn,
            'total_fee' => $wx_ret['total_fee'],
            'transaction_id' => $wx_ret['transaction_id'],
            'openid' => $wx_ret['openid'],
            'callback_data' => $xml
        ];
    }


    //获取openid
    private function getOpenId(){
        $openid = $this->getCookie($this->auth_cookie_current_openid,'');

        if( !$openid  ){
            $openid_info = OauthMemberBind::findOne([ 'member_id' => $this->current_user['id'],'type' => 1 ]);
            if( !$openid_info || !isset($openid_info['openid']) ){
                return false;
            }
            $openid = $openid_info['openid'];
        }
        return $openid;
    }

    //记录
    private function recordCallback($xml){
        $log = new FileTarget();
        $log->logFile = Yii::$app->getRuntimePath() . "/logs/wxpay_sign_".date("Ymd").".log";
        $log->messages[] = [
            "[url:{$_SERVER['REQUEST_URI']}],[xml data:{$xml}]",
            1,
            'application',
            time()
        ];
        $log->export();
    }
}
