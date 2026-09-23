# mfuko-pro-backend-2026

### Tenant URLs and chart-of-accounts setup on Vercel

The backend generates `https://<subdomain>.vercel.app/tenant/login` when the
central frontend uses a Vercel domain. Deploy the backend URL fix before checking
the tenant list again. Each generated alias must still be added to the frontend
Vercel project and permitted in `CORS_ALLOWED_ORIGINS`; tenant creation does not
provision DNS or Vercel domains. Keep `VITE_BASE_URL=vercel.app` and the central
frontend hostname in `VITE_CENTRAL_DOMAIN`.

Database seeding runs during backend tenant provisioning, independently of the
login hostname. The chart-of-accounts seeder initializes a missing or empty
SACCO_UGANDA master template and copies missing accounts into the selected tenant.
It preserves existing account names, IDs, settings and soft deletions on reruns.

After deploying, repair only Buwate's missing chart of accounts in Laravel Cloud
Commands using:

```sh
php artisan tenants:seed --tenant=buwatesacco --class='Database\Seeders\TenantChartOfAccountsSeeder' --force
```

This does not rerun the other tenant seeders or reset credentials. A seeding
failure is reported as failure, rather than allowing provisioning to silently
continue. Check Laravel Cloud logs if this command fails; do not delete and
recreate the tenant to repair its accounts.
