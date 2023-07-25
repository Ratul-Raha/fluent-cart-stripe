<?php

use FluentCart\App\Modules\PaymentMethods\BasePaymentSettings;
class StripeSettings extends BasePaymentSettings
{

    protected function getDefaultSettings()
    {
        return [
            'is_active'         => 'no',
            'payment_mode'      => 'test',
            'live_secret_key'   => '',
            'test_secret_key'   => '',
            'webhook_desc'      => '',
        ];
    }

    public function isActive()
    {
        return $this->settings['active'] == 'no';
    }


    public function getMode()
    {
        return $this->settings['payment_mode'];
    }

    public function getApiKey(){
        if($this->getMode()==='live'){
            return $this->get()['live_secret_key'];
        }else{
            return $this->get()['test_secret_key'];
        }
    }
}