<?php
namespace Topxia\Service\Order\OrderProcessor;

use Topxia\Service\Common\ServiceKernel;
use Topxia\Common\ArrayToolkit;

class VipOrderProcessor implements OrderProcessor
{
	protected $router = "vip";

	public function getRouter() {
		return $this->router;
	}

	public function getOrderInfo($targetId, $fields)
	{

        $user = $this->getUserService()->getCurrentUser();
        $member = $this->getVipService()->getMemberByUserId($user->id);
        if ($member) {
            $buyType = "renew";
        } else {
        	$buyType = "new";
        }

        $level = $this->getLevelService()->getLevel($fields['targetId']);

        $levelPrice = array(
        	'month' => $level['monthPrice'],
        	'year' => $level['yearPrice']
        );

        $unitType = $fields['unit'];
        $duration = $fields['duration'];

        $totalPrice = $levelPrice[$unitType] * $duration;

        $coinSetting = $this->getSettingService()->get("coin");

        if(!array_key_exists("coin_enabled", $coinSetting) 
            || !$coinSetting["coin_enabled"]) {
        	return array(
				'totalPrice' => $totalPrice,
				'targetId' => $targetId,
            	'targetType' => "vip",

				'level' => empty($level) ? null : $level,
				'unitType' => $unitType,
				'duration' => $duration,
				'buyType' => empty($buyType) ? null : $buyType,
        	);
        }

        $cashRate = 1;
        if(array_key_exists("cash_rate", $coinSetting)) {
            $cashRate = $coinSetting["cash_rate"];
        }

        $priceType = "RMB";
        if(array_key_exists("price_type", $coinSetting)) {
            $priceType = $coinSetting["price_type"];
        }

        
        $account = $this->getCashAccountService()->getAccountByUserId($user["id"]);
        $accountCash = $account["cash"];

        $coinPayAmount = 0;

        $hasPayPassword = strlen($user['payPassword']) > 0;
        if ($priceType == "Coin") {
            $totalPrice = $totalPrice * $cashRate;
            if($hasPayPassword && $totalPrice*100 > $accountCash*100) {
                $coinPayAmount = $accountCash;
            } else if($hasPayPassword) {
                $coinPayAmount = $totalPrice;
            }                
        } else if($priceType == "RMB") {
            if($totalPrice*100 > $accountCash/$cashRate*100) {
                $coinPayAmount = $accountCash;
            } else {
                $coinPayAmount = $totalPrice*$cashRate;
            }
        }

        return array(
            'level' => empty($level) ? null : $level,
			'unitType' => $unitType,
			'duration' => $duration,
			'buyType' => empty($buyType) ? null : $buyType,
            
            'totalPrice' => $totalPrice,
            'targetId' => $targetId,
            'targetType' => "vip",
            'cashRate' => $cashRate,
            'priceType' => $priceType,
            'account' => $account,
            'hasPayPassword' => $hasPayPassword,
            'coinPayAmount' => $coinPayAmount,
        );
        
	}

	public function shouldPayAmount($targetId, $priceType, $cashRate, $coinEnabled, $orderData)
	{
		$totalPrice = 0;

		if (!ArrayToolkit::requireds($orderData, array('buyType', 'targetId', 'unitType', 'duration'))) {
            throw new Exception('订单数据缺失，创建会员订单失败。');
        }

        if (!in_array($orderData['buyType'], array('new', 'renew'))) {
            throw new Exception('购买类型不正确，创建会员订单失败。');
        }

        $orderData['duration'] = intval($orderData['duration']);
        if (empty($orderData['duration'])) {
            throw new Exception('会员开通时长不正确，创建会员订单失败。');
        }

        if (!in_array($orderData['unitType'], array('month', 'year'))) {
            throw new Exception('付费方式不正确，创建会员订单失败。');
        }

        $level = $this->getLevelService()->getLevel($orderData['targetId']);
        if (empty($level)) {
            throw new Exception('会员等级不存在，创建会员订单失败。');
        }
        if (empty($level['enabled'])) {
            throw new Exception('会员等级已关闭，创建会员订单失败。');
        }

        $totalPrice = $level[$orderData['unitType'] . 'Price'] * $orderData['duration'];

        if ($priceType == "Coin") {
            $totalPrice = $totalPrice * $cashRate;
        }

        if(array_key_exists("coinPayAmount", $orderData)) {
            $payAmount = $this->afterCoinPay(
            	$coinEnabled, 
            	$priceType, 
            	$cashRate, 
            	$orderData['coinPayAmount'], 
            	$orderData["payPassword"]
            );

            $amount = $totalPrice - $payAmount;
        } else {
            $amount = $totalPrice;
        }

        if ($priceType == "Coin") {
            $amount = $amount/$cashRate;
        }

        $amount = ceil($amount*100)/100;

        return array(
        	$amount, 
        	$totalPrice, 
        	empty($couponResult) ? null : $couponResult,
        	empty($couponDiscount) ? null : $couponDiscount,
        );

	}

	public function createOrder($orderInfo, $fields) 
	{
		unset($orderInfo['coupon']);
		unset($orderInfo['couponDiscount']);
		
        $unitNames = array('month' => '个月', 'year' => '年');
        $level = $this->getLevelService()->getLevel($orderInfo['targetId']);

        $orderInfo['title'] = ($fields['buyType'] == 'renew' ? '续费' : '购买') .  "{$level['name']} x {$fields['duration']}{$unitNames[$fields['unitType']]}{$level['name']}会员";
        $orderInfo['targetType'] = 'vip';
        $orderInfo['snPrefix'] = 'V';
        $orderInfo['data'] = $fields;

		return $this->getOrderService()->createOrder($orderInfo);
	}

	public function updateOrder($orderInfo) 
	{
		return $this->getCourseOrderService()->updateOrder($orderId, $orderInfo);
	}

	public function doPaySuccess($success, $order) {
        if (!$success) {
            return ;
        }

        if ($order['data']['type'] == 'new') {
	        $vip = $this->getVipService()->becomeMember(
	            $order['userId'],
	            $order['data']['level'],
	            $order['data']['duration'], 
	            $order['data']['unit'], 
	            $order['id']
	        );

	        $level = $this->getLevelService()->getLevel($vip['levelId']);
	        $message = "您已经成功加入 {$level['name']} ，点击查看<a href='/vip/course/level/{$level['id']}' target='_blank'>{$level['name']}</a>课程";

	    } elseif ($order['data']['type'] == 'renew') {
	        $vip = $this->getVipService()->renewMember(
	            $order['userId'],
	            $order['data']['duration'], 
	            $order['data']['unit'], 
	            $order['id']
	        );

	        $level = $this->getLevelService()->getLevel($vip['levelId']);
	        $message = "您的 {$level['name']} 已成功续费，当前的有效期至：".date('Y-m-d', $vip['deadline']);

	    } elseif ($order['data']['type'] == 'upgrade') {
	        $vip = $this->getVipService()->upgradeMember(
	            $order['userId'],
	            $order['data']['level'], 
	            $order['id']
	        );

	        $level = $this->getLevelService()->getLevel($vip['levelId']);
	        $message = "您已经升级到 {$level['name']} ，点击查看<a href='/vip/course/level/{$level['id']}' target='_blank'>{$level['name']}</a>课程";
	    }

	    $this->getNotificationService()->notify($order['userId'], 'default', $message);

    }

    protected function getUserService()
    {
        return ServiceKernel::instance()->createService('User.UserService');
    }

    protected function getSettingService()
    {
        return ServiceKernel::instance()->createService('System.SettingService');
    }

    public function getNotificationService()
    {
        return ServiceKernel::instance()->createService('User.NotificationService');
    }

	protected function getLevelService() 
	{
		return ServiceKernel::instance()->createService("Vip:Vip.LevelService");
	}

	protected function getVipService()
    {
        return ServiceKernel::instance()->createService('Vip:Vip.VipService');
    }

    protected function getCashAccountService()
    {
        return ServiceKernel::instance()->createService('Cash.CashAccountService');
    }

    protected function getOrderService()
    {
    	return ServiceKernel::instance()->createService('Order.OrderService');
    }
}