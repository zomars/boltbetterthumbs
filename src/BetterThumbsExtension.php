<?php

namespace Bolt\Extension\cdowdy\betterthumbs;

//use Bolt\Application;
use Bolt\Asset\File\JavaScript;
use Bolt\Asset\Snippet\Snippet;
use Bolt\Asset\Target;
use Bolt\Controller\Zone;
use Bolt\Extension\SimpleExtension;
use Bolt\Filesystem as BoltFilesystem;

use Bolt\Extension\cdowdy\betterthumbs\Controller\BetterThumbsController;
use Bolt\Extension\cdowdy\betterthumbs\Helpers\Thumbnail;
use Bolt\Extension\cdowdy\betterthumbs\Handler\SrcsetHandler;
use Bolt\Extension\cdowdy\betterthumbs\Helpers\ConfigHelper;


use Bolt\Tests\Provider\PagerServiceProviderTest;
use League\Glide\Urls\UrlBuilderFactory;



/**
 * BetterThumbs extension class.
 *
 * @author Cory Dowdy <cory@corydowdy.com>
 */
class BetterThumbsExtension extends SimpleExtension
{

    private $_currentPictureFill = '3.0.2';
    private $_scriptAdded = FALSE;

    /**
     * @return array
     */
    protected function registerFrontendControllers()
    {
        $app = $this->getContainer();
        $config = $this->getConfig();
        return [
            '/img' => new BetterThumbsController($config),

        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigPaths()
    {
        return ['templates'];
    }


    /**
     * {@inheritdoc}
     */
    protected function registerTwigFunctions()
    {
        $options = ['is_safe' => ['html']];
        $this->getConfig();
        return [
            'img' => ['image',  $options ],

        ];
    }


    public function image($file, $name = 'betterthumbs', array $options = [])
    {
        $app = $this->getContainer();
        $config = $this->getConfig();

        $configName = $this->getNamedConfig($name);

        // Modifications from config merged with presets set in the config
        $configModifications = $this->getModificationParams($configName, $options);


        // get our options and merge them with ones passed from the template
        $defaultsMerged = $this->getOptions($file, $configName, $options);
        // classes merged from template
        $mergedClasses = $defaultsMerged['class'];
        $htmlClass = $this->optionToArray($mergedClasses);
        // alt text mergd from the twig template
        $altText = $defaultsMerged['altText'];
        // width denisty merged from the twig template
        $widthDensity = $defaultsMerged['widthDensity'];
        // sizes attribute merged from the twig template and made sure it's an array
        $sizesAttrib = $this->optionToArray($defaultsMerged['sizes']);
        // get the resolutions passed in from our config file
        $resolutions = $defaultsMerged['resolutions'];


        // the 'src' image parameters. get the first modifications in the first array
        $srcImgParams = current($configModifications);

        // get our helpers and handlers setup
        // This will create a srcset Array
        $srcset = new SrcsetHandler($config, $configName);

        // set the width density passed from our config
        $srcset->setWidthDensity($widthDensity);

        // This will create our fallback/src img, set alt text, classes, source image
        $thumbnail = new Thumbnail($config, $configName);

        // set our source image for the src image, set the modifications for this image and finally set the
        // alt text for the entire image element
        $thumbnail->setSourceImage($file)
            ->setModifications($srcImgParams)
            ->setAltText($altText);

        // create our src image secure URL
        $srcImg = $thumbnail->buildSecureURL();

        // get the options passed in to the parameters and prepare it for our srcset array.
        $optionWidths = $this->flatten_array($configModifications, 'w');


        $thumb = $srcset->createSrcset($file, $optionWidths, $resolutions, $configModifications);

        $context = [
            'srcImg' => $srcImg,
            'srcset' => $thumb,
            'widthDensity' => $widthDensity,
            'classes' => $htmlClass,
            'altText' => $altText,
            'sizes' => $sizesAttrib,
        ];

        $renderTemplate = $this->renderTemplate('srcset.thumb.html.twig', $context);

        return new \Twig_Markup($renderTemplate, 'UTF-8');
    }


    /**
     * @param $option
     * @param $optionType
     * @param $fallback
     * @return mixed
     */
    protected function checkIndex( $option, $optionType, $fallback )
    {
        return ( isset( $option[$optionType]) ? $option[$optionType] : $fallback );
    }


    /**
     * @param $option
     * @return array
     * take the option passed in from the template. Check if its in an array.
     * if its not an array make it one.
     * also check to make sure there is actual data in the array with array_filter
     * we only want to print a class if there is something actually there.
     */
    protected function optionToArray( $option ) {
        // check if the option that we need to be an array is in fact in an array
        $isArray = is_array($option) ? $option : array($option);

        // return the array and make sure it is not empty
        return array_filter($isArray);

    }

    function getModificationParams($config , array $options = [] )
    {
        $extConfig = $this->getConfig();
        $configName = $this->getNamedConfig($config);
        $modificationParams = isset($extConfig[$configName]['modifications']) ? $extConfig[$configName]['modifications'] : [] ;
        $presetParams = $extConfig['presets'];

        // replace parameters in 'presets' with the params in a named config
        if (isset($modificationParams) || array_key_exists('modifications', $extConfig[$configName]) ) {
            $defaults = array_merge($presetParams, $modificationParams);
        } else {
            $defaults = $presetParams;
        }

        if (isset($options['modifications'])) {
            $mergedMods = array_merge( $defaults, $options['modifications']);
        } else {
            $mergedMods = $defaults;
        }

//        return array_merge($defaults, $options);
        return $mergedMods;
    }

    protected function setAltText($namedconfig, $filename)
    {

        $configName = $this->getNamedConfig($namedconfig);
        $configFile = $this->getConfig();

        $altText = $this->checkIndex($configFile[$configName], 'altText', NULL);


        if ($altText == '~') {
            $altText = '' ;
        } elseif (empty($altText)) {
            $tempAltText = pathinfo($filename);
            $altText = $tempAltText[ 'filename' ];
        } else {
            $altText = $configFile[$configName]['altText'];
        }

        return $altText;
    }

    protected function checkWidthDensity($configName, $default = 'w' )
    {
        $extConfig = $this->getConfig();
        $namedConfig = $this->getNamedConfig($configName);
        $valid = [ 'w', 'x', 'd' ];
        $widthDensity = isset($extConfig[$namedConfig][ 'widthDensity' ]);

        if (isset($widthDensity) && !empty($widthDensity)) {
            $wd = strtolower($extConfig[$namedConfig][ 'widthDensity' ]);

            if ($wd == 'd' ) {
                $wd = 'x';
            }

        } else {
            $wd = $default;
        }
        return $wd;
    }

    public function setSizesAttrib($config)
    {
        $configName = $this->getNamedConfig($config);
        $config = $this->getConfig();

        $sizesAttrib =  isset( $config[$configName]['sizes']) ? $config[$configName]['sizes'] : ['100vw'];

        return $sizesAttrib;
    }


    protected function getOptions($filename, $config, $options =[])
    {

        $configName = $this->getNamedConfig($config);
        $config = $this->getConfig();
        $srcsetHandler = new SrcsetHandler($config, $configName);

        $altText = $this->setAltText($configName, $filename);
        $class = $this->getHTMLClass($configName);
        $sizes = $srcsetHandler->getSizesAttrib($configName);
        $defaultRes = $srcsetHandler->getResolutions();
        $widthDensity = $this->checkWidthDensity($configName);

        $defaults = [
            'widthDensity' => $widthDensity,
            'resolutions' => $defaultRes,
            'sizes' => $sizes,
            'altText' => $altText,
            'class' => $class,
        ];

        $defOptions = array_merge($defaults, $options);

        return $defOptions;
    }


    protected function getHTMLClass($namedConfig)
    {
        $configName = $this->getNamedConfig($namedConfig);
        $config = $this->getConfig();

        $class = $this->checkIndex( $config[$configName], 'class', NULL);

        return $class;
    }

    /**
     * @param $name
     * @return mixed
     *
     * get a "named config" from the extensions config file
     */
    protected function getNamedConfig($name)
    {

        if (empty( $name ) ) {
            $configName = 'betterthumbs';
        } else {
            $configName = $name ;
        }

        return  $configName ;
    }


    /**
     * Flatten The multidimensional array in the extensions config under Presets.
     *
     * @param array $array
     * @param string $fallbackOption
     * @return array
     */
    protected function flatten_array(array $array, $fallbackOption)
    {

        $fallback = [];

            foreach ($array as $key => $value) {
                if (array_key_exists($fallbackOption, $value )) {
                    $fallback[] = $value[$fallbackOption];
                } else {
                    $fallback[] = 0;
                }

            }

        return $fallback;
    }



    /**
     * You can't rely on bolts methods to insert javascript/css in the location you want.
     * So we have to hack around it. Use the Snippet Class with their location methods and insert
     * Picturefill into the head. Add a check to make sure the script isn't loaded more than once ($_scriptAdded)
     * and stop the insertion of the files multiple times because bolt's registerAssets method will blindly insert
     * the files on every page
     *
     */

    protected function addAssets()
    {
        $app = $this->getContainer();

        $config = $this->getConfig();

        $pfill = $config['picturefill'];

        $extPath = $app['resources']->getUrl('extensions');

        $vendor = 'vendor/cdowdy/';
        $extName = 'betterthumbs/';

        $pictureFillJS = $extPath . $vendor . $extName . 'picturefill/' . $this->_currentPictureFill . '/picturefill.min.js';
        $pictureFill = <<<PFILL
<script src="{$pictureFillJS}" async defer></script>
PFILL;
        $asset = new Snippet();
        $asset->setCallback($pictureFill)
            ->setZone(ZONE::FRONTEND)
            ->setLocation(Target::AFTER_HEAD_CSS);


        // add picturefill script only once for each time the extension is used
        if ($pfill){
            if ($this->_scriptAdded == FALSE ) {
                $app['asset.queue.snippet']->add($asset);
                $this->_scriptAdded = TRUE;
            } else {

                $this->_scriptAdded = TRUE;
            }
        }
    }



    /**
     * @return array
     */
    protected function getDefaultConfig()
    {
        return [
            'Image_Driver' => 'gd',
            'security' => [
                'secure_thumbs' => true,
                'secure_sign_key' => ''
            ],
            'presets' => [
                'small' => [
                    'w' => 175,
                    'fit' => 'contain'
                ],
                'medium' => [
                    'w' => 350,
                    'fit' => 'contain'
                ],
                'large' => [
                    'w' => 700,
                    'fit' => 'contain'
                ],
                'xlarge' => [
                    'w' => 1400,
                    'fit' => 'stretch'
                ],
            ],
            'betterthumbs' => [
                'save_data' => FALSE,
                'altText' => '',
                'widthDensity' => 'w',
                'sizes' => [ '100vw'],
                'modifications' => [
                    'small' => [
                        'w' => 175,
                        'fit' => 'contain'
                    ],
                    'medium' => [
                        'w' => 350,
                        'fit' => 'contain'
                    ],
                    'large' => [
                        'w' => 700,
                        'fit' => 'contain'
                    ],
                    'xlarge' => [
                        'w' => 1400,
                        'fit' => 'stretch'
                    ],
                ],
            ],

        ];
    }

    public function isSafe()
    {
        return true;
    }


}
