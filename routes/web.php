<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminCanteenController;
use App\Http\Controllers\StudentCanteenController;
use App\Http\Controllers\GpsDetectionController;
use App\Models\Order;
use App\Models\Menu;
use App\Services\CanteenManager;
use Carbon\Carbon;

// SPA Entry Points
Route::get('/', function () {
    return view('spa');
})->name('home');
Route::get('/login', function () {
    return view('spa');
})->name('login');
Route::get('/login/admin', function () {
    return view('spa');
});
Route::get('/admin/dashboard', function () {
    return view('spa');
});
Route::get('/student/dashboard', function () {
    return view('spa');
});
Route::get('/gps-verify/{token?}', function () {
    return view('spa');
});
Route::get('/lokasi-siswa/{token?}', function () {
    return view('spa');
});

// Helper penentuan loket kantin 1 sampai 7
if (!function_exists('getKantinPickupStand')) {
    function getKantinPickupStand(?string $category, ?int $canteenId = null): string {
        if ($canteenId && $canteenId >= 1 &&$canteenId <= 7) {
            $info = CanteenManager::getCanteenInfo($canteenId);
            return $info['name'];
        }

        $cat = strtolower(trim($category ?? ''));
        if (str_contains($cat, 'minum') || str_contains($cat, 'jus') \vert{}\vert{} str_contains($cat, 'teh')) return 'Kantin 3';
        if (str_contains($cat, 'sarap') || str_contains($cat, 'roti') \vert{}\vert{} str_contains($cat, 'bubur')) return 'Kantin 2';
        if (str_contains($cat, 'siang') || str_contains($cat, 'geprek') \vert{}\vert{} str_contains($cat, 'pecel')) return 'Kantin 7';
        if (str_contains($cat, 'cemi') || str_contains($cat, 'cami') \vert{}\vert{} str_contains($cat, 'gorengan')) return 'Kantin 4';
        if (str_contains($cat, 'bakso') || str_contains($cat, 'mie') \vert{}\vert{} str_contains($cat, 'soto')) return 'Kantin 5';
        if (str_contains($cat, 'burger') || str_contains($cat, 'kebab') \vert{}\vert{} str_contains($cat, 'bento')) return 'Kantin 6';
        return 'Kantin 1';
    }
}

if (!function_exists('formatOrderPickupGuide')) {
    function formatOrderPickupGuide($order): string {
        if (!$order) {
            return 'Stand Kantin 1 s/d 7 SMKN 1 Ciomas';
        }

        $cId =$order->canteen_id;
        if ($cId && $cId >= 1 &&$cId <= 7) {
            $info = CanteenManager::getCanteenInfo($cId);
            return "Ambil pesanan di Loket {$info['name']} ({$info['tagline']})";
        }

        $found = [];
        if ($order->items) {
            foreach ($order->items as$item) {
                $itemCId =$item->menu?->canteen_id ?: 1;
                $stand = getKantinPickupStand($item->menu?->category,$itemCId);
                $found[$stand] = "Ambil di {$stand}";
            }
        }
        if (!empty($found)) {
            return implode(' • ', array_values($found));
        }
        return 'Ambil di Loket Kantin SMKN 1 Ciomas';
    }
}

// API Routes
Route::get('/api/settings', function() {
    CanteenManager::initDatabase();

    $path = storage_path('app/settings.json');$settings = file_exists($path) ? json_decode(file_get_contents($path), true) : [
        'promo_image' => '',
        'promo_status' => null,
        'qris_image' => '',
        'bank_account' => 'BCA 1234567890 a.n Kantin Pintar'
    ];

    $canteens = CanteenManager::getCanteens();

    // Summary queue counts per canteen 1 to 7
    $canteenQueues = [];
    foreach ($canteens as $cId =>$cData) {
        $readyOrders = Order::where('canteen_id',$cId)->where('status', 'ready')->orderBy('updated_at', 'desc')->get();
        $cookingOrders = Order::where('canteen_id',$cId)->where('status', 'cooking')->orderBy('updated_at', 'desc')->get();
        $pendingOrders = Order::where('canteen_id',$cId)->where('status', 'pending')->orderBy('created_at', 'desc')->get();

        $canteenQueues[] = [
            'canteen_id' => $cId,
            'name' => $cData['name'],
            'title' => $cData['title'],
            'tagline' => $cData['tagline'],
            'icon' => $cData['icon'],
            'code_prefix' => $cData['code_prefix'],
            'ready_count' => $readyOrders->count(),
            'cooking_count' => $cookingOrders->count(),
            'pending_count' => $pendingOrders->count(),
            'total_active' => $readyOrders->count() + $cookingOrders->count() +$pendingOrders->count(),
            'ready_codes' => $readyOrders->pluck('order_code')->filter()->values()->all(),
            'cooking_codes' => $cookingOrders->pluck('order_code')->filter()->values()->all(),
            'latest_ready' => $readyOrders->first()?->order_code,
        ];
    }

    // Top active orders for the live ticker/carousel
    $activeOrders = Order::with('items.menu')
        ->whereIn('status', ['ready', 'cooking', 'pending'])
        ->orderByRaw("CASE WHEN status = 'ready' THEN 1 WHEN status = 'cooking' THEN 2 ELSE 3 END")
        ->orderBy('updated_at', 'desc')
        ->limit(20)
        ->get()
        ->map(function($o) {
            $cId =$o->canteen_id ?: 1;
            $cInfo = CanteenManager::getCanteenInfo($cId);