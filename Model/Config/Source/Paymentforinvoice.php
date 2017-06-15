<?php
/**
 * Created by Phong Phan.
 * Copyright Mercuriel - 2016
 * Date: 01/06/2017
 * Time: 17:08
 */
namespace Mercuriel\Autoinvoice\Model\Config\Source;

class Paymentforinvoice implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @var \Magento\Payment\Model\Config
     */
    protected $paymentModelConfig;
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $appConfigScopeConfigInterface;
    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $appConfigScopeConfigInterface
     * @param \Magento\Payment\Model\Config $paymentModelConfig
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $appConfigScopeConfigInterface,
        \Magento\Payment\Model\Config $paymentModelConfig
    ) {
        $this->paymentModelConfig = $paymentModelConfig;
        $this->appConfigScopeConfigInterface = $appConfigScopeConfigInterface;
    }

    /**
     * Multiselect collection in configuration
     * @return array
     */
    public function toOptionArray()
    {
        $payments = $this->paymentModelConfig->getActiveMethods();
        $methods = array();
        $methods[] = array(
            'label' => __('All Methods Of Payments'),
            'value' => 'mercuriel_default_all'
        );
        foreach ($payments as $paymentCode => $paymentModel)
        {
            $paymentTitle = $this->appConfigScopeConfigInterface
                ->getValue('payment/'.$paymentCode.'/title');
            $methods[] = array(
                'label' => __($paymentTitle),
                'value' => $paymentCode
            );
        }
        return $methods;
    }
}