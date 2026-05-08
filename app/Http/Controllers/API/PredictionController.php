<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Classification;
use App\Models\Plant;
use App\Filters\ImageFilters;
use Illuminate\Support\Facades\Storage;

class PredictionController extends Controller
{
    public function predict(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|max:2048',
                'filter' => 'nullable|string'
            ]);

            $image = $request->file('image');
            $filter = $request->input('filter', 'none');
            
            $path = $image->store('predictions', 'public');
            $fullPath = Storage::path('public/' . $path);
            
            // Apply filter if specified
            if ($filter !== 'none') {
                $fullPath = ImageFilters::apply($fullPath, $filter);
            }
            
            $pythonBin = base_path('scripts/venv/bin/python');
            $pythonScript = base_path('scripts/predict.py');
            $errLog = storage_path('logs/predict.err');
            $command = escapeshellarg($pythonBin) . ' ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($fullPath) . ' 2>>' . escapeshellarg($errLog);
            $output = shell_exec($command);
            
            if (!$output) {
                return response()->json([
                    'success' => false,
                    'error' => 'AI model failed to run'
                ], 500);
            }
            
            $result = json_decode($output, true);
            
            if (!$result || !isset($result['class'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'AI response invalid: ' . $output
                ], 500);
            }
            
            $plant = Plant::where('common_name', $result['class'])->first();
            
            if (!$plant) {
                return response()->json([
                    'success' => false,
                    'error' => 'Plant not found: ' . $result['class']
                ], 404);
            }
            
            $classification = Classification::create([
                'user_id' => auth()->id() ?? 1,
                'plant_id' => $plant->id,
                'image_path' => $path,
                'confidence' => $result['confidence'],
                'filter_used' => $filter,
                'device_type' => 'web'
            ]);
            
            return response()->json([
                'success' => true,
                'plant' => $plant->common_name,
                'scientific_name' => $plant->scientific_name,
                'confidence' => round($result['confidence'], 4),
                'image_url' => Storage::url($path),
                'classification_id' => $classification->id,
                'filter_applied' => $filter
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
