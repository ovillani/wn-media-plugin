<?php namespace Mercator\Media;

use Backend;
use Event;
use File;
use Request;
use System\Classes\PluginBase;
use Mercator\Media\Classes\MediaExtensions;
use Intervention\Image\ImageManagerStatic as Image;
use Winter\Storm\Database\Attach\Resizer as DefaultResizer;
use System\Models\EventLog as EventLog;

// Use native resize method for larger images, e.g. above 8 megapixels
define("NATIVE_RESIZE", (2 * 1024 * 1024));

/**
 *  Media Plugin Information File
 */
class Plugin extends PluginBase
{
    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return ['name' => 'Media', 'description' => 'Media Processing Plugin for Winter CMS, replacing resize and introducing advanced image filter capabilities based on the Intervention library.', 'author' => 'Helmut Kaufmann', 'homepage' => 'htpps://mercator.li'];
    }

    /**
     * Register method, called when the plugin is first registered.
     *
     * @return void
     */
    public function register()
    {

    }

    /**
     * Boot method, called right before the request route.
     *
     * @return array
     */
    public function boot()
    {              
        Image::configure(array(
            'driver' => 'imagick'
        ));
       
        Event::listen('system.resizer.processResize', function ($resizer, $tempPath)
        {

			EventLog::add("TEMP PATH: $tempPath");
            // Get the configuration options the user has sumitted
            $config = $resizer->getConfig();
            $options = array_get($config, 'options', []);

            $width = $config['width'];
            $height = $config['height'];
            $quality = $options["quality"];
            $extension = $options["extension"];
            $filters = array_get($config['options'], 'filters', null);

            list($base, $ext) = explode('.', $tempPath);
            $newPath = $base . '.' . array_get($options, 'extension', $ext);

            $size = getimagesize($tempPath);
            $dimensions['width'] = $size[0];
            $dimensions['height'] = $size[1];

            // Resize
            if (($width + $height) > 0)
            {

                // Resize large images with the default resizer (>8 megapixels)
                if (($dimensions["width"] * $dimensions["height"]) > NATIVE_RESIZE)
                {

                    // EventLog::add("iresize / ifilter: Native Resize " . __FILE__);

                    if (!$filters)
                    {  // End here if there are no filters to apply
                        \Winter\Storm\Database\Attach\Resizer::open($tempPath)->resize($width, $height, $options)->save($newPath);
                        if ($newPath != $tempPath) 
                        	File::move($newPath, $tempPath);
                        
    					// Prevent any other resizing replacer logic from running
                        return true;
                    }
                    else
                    {   // Save as JPG with 100% quality to limit quality deterioration
                        $intermediateOptions = $options;
                        $intermediateOptions["extension"] = 'jpg';
                        $intermediateOptions["quality"] = 100;
                        \Winter\Storm\Database\Attach\Resizer::open($tempPath)->resize($width, $height, $intermediateOptions)->save($newPath);
                        $image = Image::make($newPath);
                    }

                }
                else
                {
                    if (!$filters)
                    { // End here if there are no filters to apply
                        $image = Image::make($tempPath)->resize($width, $height)->save($newPath, $quality, $extension);
                        if ($newPath != $tempPath) 
                        	File::move($newPath, $tempPath);
                        	
                        	// Prevent any other resizing replacer logic from running
                        	return true;
                    }
                    else 
                    {
                    	// Resize
                    	$image = Image::make($tempPath)->resize($width, $height);
                    }
                }
            }
            else $image = Image::make($tempPath);

            // Apply filters
            if (is_array($filters))
            {

                foreach ($filters as $filter)
                {

                    $arguments = array_values($filter);
                    switch ($arguments[0])
                    {

                        case 'blur':
                            $image = $image->blur(isset($arguments[1]) ? $arguments[1] : 1);
                        break;

                        case 'brightness':
                            $image = $image->brightness($arguments[1]);
                        break;

                        case 'colorize':
                            $image = $image->colorize($arguments[1], $arguments[2], $arguments[3]);
                        break;

                        case 'contrast':
                            $image = $image->heighten($arguments[1]);
                        break;

                        case 'crop':
                            $image = $image->crop($arguments[1], $arguments[2], isset($arguments[3]) ? $arguments[3] : 0, isset($arguments[4]) ? $arguments[4] : 0);
                        break;

                        case 'flip':
                            $image = $image->flip(isset($arguments[1]) ? $arguments[1] : "v");
                        break;

                        case 'gamma':
                            $image = $image->gamma($arguments[1]);
                        break;

                        case 'greyscale':
                            $image = $image->greyscale();
                        break;

                        case 'heighten':
                            $image = $image->heighten($arguments[1]);
                        break;

                            /* Not working - review needed
                            
                            case 'invert':
                            $image=$image->invert();
                            break;
                            
                            */

                        case 'limitColors':
                            $image = $image->limitColors($arguments[1], isset($arguments[2]) ? $arguments[2] : null);
                        break;

                        case 'opacity':
                            $image = $image->opacity($arguments[1]);
                        break;

                        case 'pixelate':
                            $image = $image->pixelate($arguments[1]);
                        break;

                        case 'resize':
                            $image = $image->resize($arguments[1], isset($arguments[2]) ? $arguments[2] : null);
                        break;

                        case 'rotate':
                            $image = $image->rotate($arguments[1]);
                        break;

                        case 'sharpen':
                            $image = $image->sharpen(isset($arguments[1]) ? $arguments[1] : 10);
                        break;

                        case 'widen':
                            $image = $image->widen($arguments[1]);
                        break;

                        default:
                            EventLog::add("iresize / ifilter: Detected and ignored unknown filter >>" . $arguments[0] . "<<. See " . __FILE__);
                    }

                }
            }
            elseif (strcmp($filters, "")) $image = eval("{ return \$image->" . $filters . "; }");

            $image->save($newPath, $quality, $extension);
            if ($newPath != $tempPath) 
            	File::move($newPath, $tempPath);

            // Prevent any other resizing replacer logic from running
            return true;

        });
        
    } 
         
        

    /**
     * Registers any front-end components implemented in this plugin.
     *
     * @return array
     */
    public function registerComponents()
    {
        return []; // Remove this line to activate
        
    }

    /**
     * Registers any back-end permissions used by this plugin.
     *
     * @return array
     */
    public function registerPermissions()
    {
        return []; // Remove this line to activate
        
    }

    /**
     * Registers back-end navigation items for this plugin.
     *
     * @return array
     */
    public function registerNavigation()
    {
        return []; // Remove this line to activate
        
    }

    public function registerMarkupTags()
    {
        return ['filters' => ['iresize' => [MediaExtensions::class , 'iresize'], 'ifilter' => [MediaExtensions::class , 'iresize']

        ], 'functions' => ['exif' => [MediaExtensions::class , 'exif'], 'iptc' => [MediaExtensions::class , 'iptc'], ], ];
    }
}
