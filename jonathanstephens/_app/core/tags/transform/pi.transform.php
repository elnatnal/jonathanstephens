<?php

use Intervention\Image\Image;

/**
 * Plugin_transform
 * Manipulate the crap out of your images!
 *
 * @author  Jack McDade <jack@statamic.com>
 * @author  Fred LeBlanc <fred@statamic.com>
 * @author  Mubashar Iqbal <mubs@statamic.com>
 *
 * @copyright  2013
 * @link       http://statamic.com/learn/documentation/tags/transform
 * @license    http://statamic.com/license-agreement
 */

class Plugin_transform extends Plugin
{
    public function index()
    {

        /*
        |--------------------------------------------------------------------------
        | Create Image
        |--------------------------------------------------------------------------
        |
        | Transform just needs the path to an image to get started. The image is
        | created in memory so we can manipulate it. Get a move on.
        |
        */

        $image_src = $this->fetchParam('src', null, false, false, false);

        // Set full system path
        $image_path = Path::tidy(BASE_PATH . $image_src);

        // Check if image exists before doing anything.
        if ( ! File::exists($image_path)) {

            Log::error("Could not find requested image to transform: " . $image_path, "core", "Transform");

            return;
        }

        $image = Image::make($image_path);


        /*
        |--------------------------------------------------------------------------
        | Resizing and cropping options
        |--------------------------------------------------------------------------
        |
        | The first transformations we want to run is for size to reduce the
        | memory usage for future effects.
        |
        */

        $width  = $this->fetchParam('width', null, 'is_numeric');
        $height = $this->fetchParam('height', null, 'is_numeric');

        // resize specific
        $ratio  = $this->fetchParam('ratio', true, false, true);
        $upsize = $this->fetchParam('upsize', true, false, true);

        // crop specific
        $pos_x  = $this->fetchParam('pos_x', 0, 'is_numeric');
        $pos_y  = $this->fetchParam('pos_y', 0, 'is_numeric');

        $quality = $this->fetchParam('quality', '75', 'is_numeric');


        /*
        |--------------------------------------------------------------------------
        | Action
        |--------------------------------------------------------------------------
        |
        | Available actions: resize, crop, and guess.
        |
        | "Guess" will find the best fitting aspect ratio of your given width and
        | height on the current image automatically, cut it out and resize it to
        | the given dimension.
        |
        */

        $action = $this->fetchParam('action', 'resize');


        /*
        |--------------------------------------------------------------------------
        | Extra bits
        |--------------------------------------------------------------------------
        |
        | Delicious and probably rarely used options.
        |
        */

        $angle     = $this->fetchParam('rotate', false);
        $flip_side = $this->fetchParam('flip' , false);
        $blur      = $this->fetchParam('blur', false, 'is_numeric');
        $pixelate  = $this->fetchParam('pixelate', false, 'is_numeric');

        $grayscale = $this->fetchParam('grayscale', false, false, true); // Silly brits
        $greyscale = $this->fetchParam('greyscale', $grayscale, false, true);


        /*
        |--------------------------------------------------------------------------
        | Assemble filename and check for duplicate
        |--------------------------------------------------------------------------
        |
        | We need to make sure we don't already have this image created, so we
        | defer any action until we've processed the parameters, which create
        | a unique filename.
        |
        */

        // Find .jpg, .png, etc
        $extension = File::getExtension($image_path);

        // Filename with the extension removed so we can append our unique filename flags
        $stripped_image_path = str_replace('.' . $extension, '', $image_path);

        // The possible filename flags
        $parameter_flags = array(
            'width'     => $width,
            'height'    => $height,
            'quality'   => $quality,
            'rotate'    => $angle,
            'flip'      => $flip_side,
            'pos_x'     => $pos_x,
            'pos_y'     => $pos_y,
            'blur'      => $blur,
            'pixelate'  => $pixelate,
            'greyscale' => $greyscale
        );

        // Start with a 1 character action flag
        $file_breadcrumbs = '-'.$action[0];

        foreach ($parameter_flags as $param => $value) {
            if ($value) {
                $flag = is_bool($value) ? '' : $value; // don't show boolean flags
                $file_breadcrumbs .= '-' . $param[0] . $flag;
            }
        }

        // Allow converting filetypes (jpg, png, gif)
        $extension = $this->fetchParam('type', $extension);

        // Allow saving in a different directory
        $destination = $this->fetchParam('destination', Config::get('transform_destination', false), false, false, false);

        if ($destination) {
            $stripped_image_path = Path::tidy(BASE_PATH . '/' . $destination . '/' . basename($stripped_image_path));
        }

        // Reassembled filename with all flags filtered and delimited
        $new_image_path = $stripped_image_path . $file_breadcrumbs . '.' . $extension;

        // Check if we've already built this image before
        if (File::exists($new_image_path)) {

            return File::cleanURL($new_image_path);
        }

        /*
        |--------------------------------------------------------------------------
        | Perform Actions
        |--------------------------------------------------------------------------
        |
        | This is fresh transformation. Time to work the magic!
        |
        */

        if ($action === 'resize' && ($width || $height) ) {
            $image->resize($width, $height, $ratio, $upsize);
        }

        if ($action === 'crop' && $width && $height) {
            $image->crop($width, $height, $pos_x, $pos_y);
        }

        if ($action === 'smart') {
            $image->grab($width, $height);
        }

        if ($angle) {
            $image->rotate($angle);
        }

        if ($flip_side === 'h' || $flip_side === 'v') {
            $image->flip($flip_side);
        }

        if ($greyscale) {
            $image->greyscale();
        }

        if ($blur) {
            $image->blur($blur);
        }

        if ($pixelate) {
            $image->pixelate($pixelate);
        }


        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        |
        | Get out of dodge!
        |
        */

        $image->save($new_image_path, $quality);

        return File::cleanURL($new_image_path);
    }
}
