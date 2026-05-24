<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require_once dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Staff;
use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

$tenant = Tenant::where('subdomain', 'wazalendosacco')->first();
app(\App\Infrastructure\Tenancy\DatabaseSwitcher::class)->switch($tenant);

$staff = Staff::find(1);
if (!$staff) {
    echo "Staff not found\n";
    exit(1);
}

echo "Staff name: " . $staff->name . "\n";

Storage::fake('public');
$file = UploadedFile::fake()->image('avatar.jpg');

try {
    $path = $file->store('avatars', 'public');
    echo "Stored path: " . $path . "\n";
    $staff->update(['avatar' => $path]);
    echo "Updated staff avatar column\n";
    echo "Avatar URL: " . $staff->avatar_url . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
