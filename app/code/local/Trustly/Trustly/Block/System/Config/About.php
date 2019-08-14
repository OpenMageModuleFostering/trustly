<?php

class Trustly_Trustly_Block_System_Config_About extends Mage_Adminhtml_Block_Abstract implements Varien_Data_Form_Element_Renderer_Interface
{

	public function render(Varien_Data_Form_Element_Abstract $element)
	{
		$html = '<div style="margin-bottom:10px; padding:10px 5px 5px 10px; height: 400px; ">
				<div><iframe src="https://trustly.com/magentosignup" style="width: 100%; height: 400px; border: 1px solid #D6D6D6;"></iframe></div>
			</div>';

        return $html;
    }
}
