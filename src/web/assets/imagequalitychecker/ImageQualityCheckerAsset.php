<?php

namespace arjanbrinkman\craftimagequalitychecker\web\assets\imagequalitychecker;

use Craft;
use craft\web\AssetBundle;

/**
 * Image Quality Checker asset bundle
 */
class ImageQualityCheckerAsset extends AssetBundle
{
   public function init()
   {
	   $this->sourcePath = __DIR__ . '/dist';
	   $this->depends = [
		   CpAsset::class,
	   ];
	   $this->js = [
		   'js/check.js'
	   ];
	   $this->css = [
		   //'css/style.css'
	   ];
	   
	   parent::init();
   }
}
