<?php

namespace App\Domain\Tenancy\Entities;

use App\Concerns\HasDynamicConnection;
use App\Domain\Licensing\Entities\License;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasDynamicConnection, HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return TenantFactory::new();
    }

    protected $connection = 'master';

    protected $fillable = [
        'id',
        'name',
        'subdomain',
        'domain',
        'database_name',
        'status',
        'settings',
    ];

    protected $appends = [
        'full_domain',
        'full_url',
    ];

    protected $casts = [
        'id' => 'string',
        'settings' => 'json',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Get the full domain for this tenant.
     * Falls back to subdomain-based domain if custom domain is not set.
     */
    public function getFullDomainAttribute(): string
    {
        if ($this->domain) {
            return $this->domain;
        }

        $centralDomain = config('app.central_domain');

        // Extract the base domain from the central domain.
        // If central domain is admin.mfukopro.test, we want mfukopro.test.
        // If central domain is mfukopro.com, we want mfukopro.com.
        $parts = explode('.', $centralDomain);
        if (count($parts) > 2) {
            // Remove the first part (e.g., 'admin' or 'central')
            array_shift($parts);
        }
        $baseDomain = implode('.', $parts);

        return $this->subdomain.'.'.$baseDomain;
    }

    /**
     * Get the full URL for this tenant, including protocol and port if necessary.
     */
    public function getFullUrlAttribute(): string
    {
        $request = request();
        $scheme = $request->getScheme();
        $port = $request->getPort();
        $host = $request->getHost();

        // If we are on localhost/127.0.0.1, use localhost as the base for subdomains
        if (in_array($host, ['localhost', '127.0.0.1'])) {
            $domain = $this->subdomain.'.localhost';
        } else {
            $domain = $this->full_domain;
        }

        $url = $scheme.'://'.$domain;

        if ($port && ! in_array($port, [80, 443])) {
            $url .= ':'.$port;
        }

        return $url;
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function activeLicense(): HasOne
    {
        return $this->hasOne(License::class)->where('status', 'active');
    }

    /**
     * Get the admin email for this tenant (from settings or latest active license context).
     */
    public function getAdminEmailAttribute(): ?string
    {
        return $this->settings['admin_email'] ?? null;
    }
}
