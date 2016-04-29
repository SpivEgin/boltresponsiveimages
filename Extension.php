<?php


namespace Bolt\Extension\cdowdy\boltresponsiveimages;

use Bolt\Application;
use Bolt\BaseExtension;
use Bolt\Thumbs;

class Extension extends BaseExtension
{

    private $_currentPictureFill = '3.0.1';
    private $_currentLazySizes = '1.4.0';

    public function initialize()
    {
        if ( $this->app['config']->getWhichEnd() == 'frontend' ) {
            $this->addTwigFunction('respImg', 'respImg');
        }
    }

    /**
     * @return string
     */
    public function getName()
    {
        return "boltresponsiveimages";
    }


    public function respImg($file, $name, array $options = array())
    {

        // add picturefill if its set to true in the extension config. Defaults to true
        if ($this->config[ 'picturefill' ] == true) {
            $this->addAssets();
        }


        // get the config file name if using one. otherwise its 'default'
        $configName = $this->getConfigName($name);

        // gather the default options, merge them with any options passed in the template
        $defaultOptions = $this->getOptions($file, $configName, $options);


        // if a class is set in the config or options pass it to the template
        $htmlClass = $defaultOptions[ 'class' ];

        // test for lazyload
        $lazy = $defaultOptions['lazyLoad'];
        
        
        // add the class "lazyload" to the class array in the template or config
        // also load the lazysizes script in the head with an async tag
        if ($lazy) {
            $htmlClass[] = 'lazyload';
            $this->lazyLoadScript();
        }
        /**
         * other options here.
         *
         * could take the config classes and merge them with the classes passed in the template
         * so that config classes carry over into the template overrides
         *
         * this would mean that a twig function
         * {{ respImg( record.image, 'default', { class: [ htmlClass] }) }}
         * would produce :
         * <img class=" configClass  htmlClass ">
         * 
         * $tempClass = $defaultOptions[ 'class' ];
         * $configClass = $this->config[ $configName ]['class'];
         *
         * if ( $tempClass != $configClass ) {
         *  $htmlClass = array_merge($tempClass, $configClass);
         * } else {
         *  $htmlClass = $tempClass;
         * }
         *
         */

        $optionsWidths = $defaultOptions[ 'widths' ];
        $optionHeights = $defaultOptions[ 'heights' ];
        $resolutions = $defaultOptions[ 'resolutions' ];

        // get the alt text for the Image
        // $altText = $this->getAltText($configName, $file);
        $altText = $defaultOptions['altText'];

        // get size attribute if using the W descriptor
        $sizeAttrib = $defaultOptions[ 'sizes' ];

        // Combine the Heights and Widths to use for our thumbnail parameters
        $sizeArray = $this->getCombinedArray($optionsWidths, $optionHeights, 0);


        // get what we need for the cropping parameter
        $cropping = $defaultOptions[ 'cropping' ];

        $densityWidth = $defaultOptions[ 'widthDensity' ];

        // make thumbs an empty array
        $thumb = array();
        // loop through the size array and generate a thumbnail and URL
        // place those in an array to be used in the twig template
        foreach ($sizeArray as $key => $value) {
            $thumb[] .= $this->thumbnail($file, $key, $value, $cropping);
        }

        // use the array below if using the W descriptor
        if ($densityWidth == 'w') {
            $combinedImages = array_combine($thumb, $optionsWidths);
        }

        if ($densityWidth == 'x') {
            $combinedImages = $this->resolutionErrors($thumb, $resolutions);
        }

        // get the smallest (first sizes in the size array) heights and widths for the src image
        $srcThumbWidth = $defaultOptions[ 'widths' ][ 0 ];
        $srcThumbHeight = $defaultOptions[ 'heights' ][ 0 ];

        // if not using picturefill place the smallest image in the "src" attribute of the img tag
        // <img srcset="" src="smallest image here" alt="alt text" >
        $srcThumb = $this->thumbnail($file, $srcThumbWidth, $srcThumbHeight, $cropping);

        // load up twig template directory
        $this->app[ 'twig.loader.filesystem' ]->addPath(__DIR__ . "/assets");

        $renderImg = $this->app[ 'render' ]->render('respimg.twig', array(
            'alt' => $altText,
            'sizes' => $sizeAttrib,
            'options' => $defaultOptions,
            'widthDensity' => $densityWidth,
            'combinedImages' => $combinedImages,
            'srcThumb' => $srcThumb,
            'class' => $htmlClass,
            'sizeArray' => $sizeArray,
            'lazy' => $lazy

        ));

        return new \Twig_Markup($renderImg, 'UTF-8');
    }


    /**
     * @param $name
     *
     * @return string
     *
     * get the config name. If no name is passed in the twig function then use
     * the default settings in our config file under defaults
     */
    function getConfigName($name)
    {

        if (empty($name)) {

            $configName = 'default';

        } else {

            $configName = $name;

        }

        return $configName;
    }


    /**
     * @param       $filename
     * @param       $config
     * @param array $options
     *
     * @return array
     *
     * Get the default options
     */
    function getOptions($filename, $config, $options = array())
    {

        $configName = $this->getConfigName($config);
        $defaultWidths = $this->getWidthsHeights($configName, 'widths');
        $defaultHeights = $this->getWidthsHeights($configName, 'heights');
        $defaultRes = $this->getResolutions($configName);
        $cropping = $this->getCropping($configName);
        $altText = $this->getAltText($configName, $filename);
        $widthDensity = $this->getWidthDensity($configName);
        $sizes = $this->getSizesAttrib($configName);
        $class = $this->getHTMLClass($configName);
        $lazyLoaded = $this->setLazyLoad($configName);


        $defaults = array(
            'widths' => $defaultWidths,
            'heights' => $defaultHeights,
            'cropping' => $cropping,
            'widthDensity' => $widthDensity,
            'resolutions' => $defaultRes,
            'sizes' => $sizes,
            'altText' => $altText,
            'lazyLoad' => $lazyLoaded,
            'class' => $class

        );

        $defOptions = array_merge($defaults, $options);

        return $defOptions;
    }


    /**
     * @param $config
     * @param $filename
     *
     * @return mixed
     */
    function getAltText($config, $filename)
    {

        $configName = $this->getConfigName($config);
        $altText = $this->config[ $configName ][ 'altText' ];

        if (empty($altText)) {
            $tempAltText = pathinfo($filename);
            $altText = $tempAltText[ 'filename' ];
        }

        return $altText;
    }


    /**
     * @param $config
     * @param $option
     *
     * @return mixed
     */
    function getWidthsHeights($config, $option)
    {

        $configName = $this->getConfigName($config);
        $configOption = $this->config[ $configName ][ $option ];

        if (isset($configOption) && !empty($configOption)) {
            $configParam = $this->config[ $configName ][ $option ];
        } else {
            $configParam = $this->config[ 'default' ][ $option ];
        }

        return $configParam;
    }

    /**
     * @param $config
     *
     * @return array
     *
     * get the resolutions for resolution switching
     */
    function getResolutions($config)
    {
        $configName = $this->getConfigName($config);
        $resOptions = $this->config[ $configName ][ 'resolutions' ];

        if (isset($resOptions) && !empty($resOptions)) {
            $resolutions = $this->config[ $configName ][ 'resolutions' ];
        } else {
            $resolutions = array(
                1,
                2,
                3
            );
        }

        return $resolutions;
    }

    /**
     * @param $thumb
     * @param $resolutions
     *
     * @return string
     */
    function resolutionErrors($thumb, $resolutions)
    {
        $thumbCount = count($thumb);
        $resCount = count($resolutions);

        // if the resolutions are more than the thumbnails remove the resolutions to match the thumbnail array
        if ($resCount > $thumbCount) {
            $newResArray = array_slice($resolutions, 0, $thumbCount);
            $resError = array_combine($thumb, $newResArray);
        }

        // if the resolution count is smaller than the number of thumbnails remove the number of thumbnails
        // to match the $resCount Array
        if ($resCount < $thumbCount) {
            $newThumbArray = array_slice($thumb, 0, $resCount);
            $resError = array_combine($newThumbArray, $resolutions);
        }

        if ($resCount === $thumbCount ) {
            $resError = array_combine( $thumb, $resolutions);
        }


        return $resError;
    }


    /**
     * @param $option1
     * @param $option2
     * @param $padValue
     *
     * @return array
     */
    function getCombinedArray($option1, $option2, $padValue)
    {
        $option1Count = count($option1);
        $option2Count = count($option2);

        if ($option1Count != $option2Count) {
            $option1Array = array_pad($option1, $option2Count, $padValue);
        } else {
            $option1Array = $option1;
        }

        if ($option2Count != $option1Count) {
            $option2Array = array_pad($option2, $option1Count, $padValue);
        } else {
            $option2Array = $option2;
        }

        $combinedArray = array_combine($option1Array, $option2Array);

        return $combinedArray;

    }


    /**
     * @param $config
     *
     * @return mixed
     */
    function getWidthDensity($config)
    {
        $configName = $this->getConfigName($config);
        $widthDensity = $this->config[ $configName ][ 'widthDensity' ];

        if (isset($widthDensity) && !empty($widthDensity)) {
            $wd = strtolower($this->config[ $configName ][ 'widthDensity' ]);
        } else {
            $wd = strtolower($this->config[ 'default' ][ 'widthDensity' ]);
        }

        return $wd;
    }

    /**
     * @param $config
     *
     * @return mixed
     */
    function getCropping($config)
    {
        $configName = $this->getConfigName($config);
        $cropping = $this->config[ $configName ][ 'cropping' ];

        if (isset($cropping) && !empty($cropping)) {
            $crop = $this->config[ $configName ][ 'cropping' ];
        } else {
            $crop = $this->config[ 'default' ][ 'cropping' ];
        }

        return $crop;
    }

    /**
     * @param $config
     *
     * @return mixed
     */
    function getSizesAttrib($config)
    {
        $configName = $this->getConfigName($config);
        $sizes = $this->config[ $configName ][ 'sizes' ];

        if (isset($sizes) && !empty($sizes)) {
            $sizesAttrib = $this->config[ $configName ][ 'sizes' ];
        } else {
            $sizesAttrib = $this->config[ 'default' ][ 'sizes' ];
        }

        return $sizesAttrib;
    }

    /**
     * @param $config
     *
     * @return mixed
     */
    function getHTMLClass($config)
    {
        $configName = $this->getConfigName($config);
        $htmlClass = $this->config[ $configName ][ 'class' ];

        $class = $this->config[ 'default' ][ 'class' ];

        // if a class array is in the config set the $class variable to the class array
        if ( isset($htmlClass ) ) {
            $class = $htmlClass;
        }

        return $class;
    }

    function setLazyLoad($config) {

        $configName = $this->getConfigName($config);
        $lazyLoad = $this->config[ $configName ]['lazyLoad'];


        if ($lazyLoad) {

            return TRUE;
        }

        return FALSE;
    }

    function lazyLoadScript() {
        $lazySizesJS = $this->getBaseUrl() . 'js/lazysizes/' . $this->_currentLazySizes . '/lazysizes.min.js';
        $lazySizes = <<<LAZYLOAD
<script src="{$lazySizesJS}" async ></script>
LAZYLOAD;
        // insert snippet after the last CSS file in the head
        $this->addSnippet('afterheadcss', $lazySizes);
    }

    /**
     * @param        $filename
     * @param string $width
     * @param string $height
     * @param string $zoomcrop
     *
     * @return mixed
     */
    public function thumbnail($filename, $width = '', $height = '', $zoomcrop)
    {
        $thumbConfig = $this->app[ 'config' ]->get('general/thumbnails');

        if (!is_numeric($width)) {
            $width = empty($thumbConfig[ 'default_thumbnail' ][ 0 ]) ? 100 : $thumbConfig[ 'default_thumbnail' ][ 0 ];
        }

        if (!is_numeric($height)) {
            $height = empty($thumbConfig[ 'default_thumbnail' ][ 1 ]) ? 100 : $thumbConfig[ 'default_thumbnail' ][ 1 ];
        }


        switch ($zoomcrop) {
            case 'fit':
            case 'f':
                $scale = 'f';
                break;

            case 'resize':
            case 'r':
                $scale = 'r';
                break;

            case 'borders':
            case 'border':
            case 'b':
                $scale = 'b';
                break;

            case 'crop':
            case 'c':
                $scale = 'c';
                break;

            default:
                $scale = !empty($thumbconf[ 'cropping' ]) ? $thumbconf[ 'cropping' ] : 'c';
        }

        // After v1.5.1 we store image data as an array
        if (is_array($filename)) {
            $filename = isset($filename[ 'filename' ]) ? $filename[ 'filename' ] : $filename[ 'file' ];
        }


        $path = $this->app[ 'url_generator' ]->generate(
            'thumb',
            array(
                'thumb' => round($width) . 'x' . round($height) . $scale . '/' . $filename,

            )
        );

        return $path;
    }
   

//    /* create a low quality image placeholder */
//    function lqip( $file, $width, $height )
//    {
//
//        $newResizer = new Thumbs\ThumbnailResponder();
////
//        $quality = $this->app['config']->get('general/thumbnails/quality') / 2;
//
//        $newResizer->initialize()
//            ->quality = $quality;
//    }

    /**
     * Add Picturefill to the current page!!!
     */
    private function addAssets()
    {
        /**
         * since there is no head function or any reliable way to insert anything in to the head in Bolt we have to
         * hackishly insert picturefill into the head this way.
         *
         * first we assign a variable ($pictureFillJS) to the base URL
         * then insert that variable into a heredoc
         */
        $pictureFillJS = $this->getBaseUrl() . 'js/picturefill/' . $this->_currentPictureFill . '/picturefill.min.js';
        $pictureFill = <<<PFILL
<script src="{$pictureFillJS}" async defer></script>
PFILL;

        if ($this->config[ 'picturefill' ] == true) {
            // insert snippet after the last CSS file in the head
            $this->addSnippet('afterheadcss', $pictureFill);
        }
    }


    public function isSafe()
    {
        return true;
    }
}
