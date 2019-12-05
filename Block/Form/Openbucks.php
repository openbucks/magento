<?php

namespace Openbucks\Openbucks\Block\Form;

/**
 * Abstract class for Openbucks payment method form
 */
abstract class Openbucks extends \Magento\Payment\Block\Form
{
    protected $_instructions;
    protected $_template = 'form/openbucks_form.phtml';
}
