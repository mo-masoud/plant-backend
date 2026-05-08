<?php
namespace App\Filters;

use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Storage;

class ImageFilters
{
    public static function apply($imagePath, $filter)
    {
        $manager = new ImageManager(['driver' => 'gd']);
        $image = $manager->make($imagePath);
        
        switch ($filter) {
            case 'grayscale':
                $image->greyscale();
                break;
            case 'blur':
                $image->blur(5);
                break;
            case 'edge':
                $image->contrast(50);
                $image->sharpen(10);
                break;
            default:
                return $imagePath;
        }
        
        $newPath = str_replace('.', "_$filter.", $imagePath);
        $image->save($newPath);
        return $newPath;
    }
}
