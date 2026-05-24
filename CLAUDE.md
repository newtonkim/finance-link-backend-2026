# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Mfuko Pro is a multi-tenant SaaS platform for digitizing Savings and Credit Cooperative Organizations (Saccos). It consists of:

- **Backend**: Laravel 12 API (mfuko-pro-backend-2026) with multi-tenant architecture
- **Frontend**: Vue 3 SPA (mfuko-pro-frontend-2026) with Vite and Tailwind CSS  
- **Database**: PostgreSQL with tenant separation via databases

## Development Commands

### Backend (Laravel) - Navigate to mfuko-pro-backend-2026

```bash
# Initial setup
composer setup                 # Full setup (install, migrate, build)

# Development
composer dev                   # Run all services concurrently (server, queue, logs, vite)
composer dev:ssr              # Run with SSR support
php artisan serve             # Run server only
php artisan queue:listen      # Run queue processor
php artisan pail              # View real-time logs

# Database
php artisan migrate           # Run migrations for central DB
composer migrate:landlord     # Run landlord (central) migrations  
composer migrate:tenants      # Migrate all tenant databases
php artisan migrate:fresh --seed  # Reset and seed database

# Code quality
composer lint                 # Fix code style with Pint
composer test:lint           # Check code style without fixing
composer test                # Run full test suite (lint + tests)
php artisan test             # Run PHP tests only

# Build
npm run build                # Build frontend assets
npm run build:ssr           # Build with SSR support
npm run format              # Format with Prettier
npm run lint                # Run ESLint
```

### Frontend (Vue 3) - Navigate to mfuko-pro-frontend-2026

```bash
# Development
pnpm install                 # Install dependencies (uses pnpm)
pnpm dev                    # Start Vite dev server

# Build and Production
pnpm build                  # Type-check and build
pnpm preview                # Preview production build
pnpm type-check            # Run TypeScript checks

# Code Quality
pnpm lint                   # Run all linters (oxlint + eslint)
pnpm lint:oxlint           # Run oxlint only
pnpm lint:eslint           # Run eslint only
pnpm format                # Format code with Prettier

# Testing
pnpm test:unit             # Run unit tests with Vitest
```

## Architecture

### Multi-Tenancy Strategy

The system uses database-per-tenant isolation:

1. **Central Database**: Manages tenants, licenses, plans, and platform users
2. **Tenant Databases**: Separate database for each Sacco (tenant)
3. **Middleware**: `tenant.api` middleware handles tenant context switching
4. **API Structure**:
   - `/api/v1/central/*` - Platform admin endpoints
   - `/api/v1/tenant/*` - Tenant-specific endpoints

### Backend Structure

```
app/
├── Actions/Fortify/      # Laravel Fortify authentication actions
├── Central/              # Multi-tenant management
│   ├── Console/         # Tenant management CLI commands
│   ├── Http/           # Controllers for tenant management
│   ├── Models/         # Central models (Tenant, License, Plan)
│   └── Services/       # TenantProvisioningService, LicenseService
├── Domain/               # Domain-driven design layer
│   ├── Licensing/      # License management domain
│   └── Tenancy/        # Tenancy management domain
├── Infrastructure/       # Infrastructure layer
│   └── Tenancy/        # Tenant resolution and switching
└── Tenant/               # Tenant-specific functionality
    ├── Http/           # API controllers and resources
    └── Modules/        # Domain modules
        ├── Accounting/   # General ledger, journals, charts
        ├── Groups/       # Group management
        ├── Loans/        # Loan management
        ├── Savings/      # Savings accounts and products
        ├── Shares/       # Share management
        └── Transactions/ # Transaction processing
```

### Frontend Structure

Vue 3 application with:
- **Pinia** for state management
- **Vue Router** for navigation
- **Axios** for API communication
- **Tailwind CSS 4** for styling
- **Reka UI** components
- **TypeScript** for type safety
- **Vite** for build tooling

### Key Models & Modules

**Core Banking Modules**:
- Members (KYC, beneficiaries, next of kin)
- Savings (products, accounts, interest calculation)
- Loans (applications, schedules, guarantors, disbursement)
- Shares (purchase, transfer, dividends)
- Accounting (double-entry, journal entries, general ledger)
- Transactions (deposits, withdrawals, transfers)

## API Authentication

- Uses Laravel Sanctum for authentication
- JWT tokens for API access
- Tenant context determined from subdomain or API header
- Fortify guard: `platform` for central admin access

## Database Configuration

### Connections
- `master` - Central/landlord database
- `tenant` - Dynamic tenant database connection

### Migrations
- `database/migrations/landlord/` - Central database migrations
- `database/migrations/tenant/` - Tenant database migrations

### Environment Variables
```bash
DB_CONNECTION=master
CENTRAL_DOMAIN=admin.mfukopro.test
FRONTEND_URL=http://localhost:5173  # development
```

## Testing Strategy

- **Backend**: PHPUnit/Pest for testing
- **Frontend**: Vitest for unit tests
- Test database: `mfukopro_test` 
- Run tests with: `composer test` or `php artisan test`

## Important Considerations

1. **Tenant Isolation**: Always ensure queries are scoped to the current tenant
2. **Accounting Compliance**: Maintain double-entry bookkeeping principles
3. **Audit Trail**: Track all financial transactions and changes
4. **Security**: Use middleware for authentication and tenant verification
5. **Queue Processing**: Financial operations use Laravel queues for reliability
6. **Dynamic Connections**: Models use `HasDynamicConnection` trait for tenant switching

## Common Development Tasks

### Tenant Management Commands
```bash
# Create a new tenant
php artisan tenant:create --name="Sacco Name" --subdomain="sacco"

# List all tenants
php artisan central:tenant:list

# Suspend a tenant
php artisan central:tenant:suspend --subdomain="sacco"

# Migrate specific tenant
php artisan tenants:migrate --tenant=1

# Seed specific tenant
php artisan tenants:seed --tenant=1
```

### License Management
```bash
# Extend a tenant license
php artisan central:license:extend --tenant=1 --days=30
```

### Accessing Tenant Context in Code
```php
// Get current tenant
$tenant = app('currentTenant');

// Switch tenant database
$tenantDb = tenant_db($tenant->database_name);

// Use dynamic connection
Model::on('tenant')->where(...);
```

### API Request Headers
```
Authorization: Bearer {token}
X-Tenant-Subdomain: {tenant-subdomain}
```

## Stack & Dependencies

### Backend
- **PHP**: ^8.2
- **Laravel**: ^12.0
- **Inertia.js**: ^2.0
- **Laravel Sanctum**: ^4.3
- **Laravel Fortify**: ^1.30
- **Maatwebsite Excel**: ^3.1

### Frontend
- **Vue**: ^3.5.29
- **TypeScript**: ~5.9.3
- **Vite**: ^7.3.1
- **Pinia**: ^3.0.4
- **Reka UI**: ^2.9.0
- **Tailwind CSS**: ^4.2.1
- **Axios**: ^1.13.6

### Development Tools
- **Laravel Pint**: Code formatting
- **ESLint & Oxlint**: JavaScript linting
- **Prettier**: Code formatting
- **Pest/PHPUnit**: PHP testing
- **Vitest**: JavaScript testing