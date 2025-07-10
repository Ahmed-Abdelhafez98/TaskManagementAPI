# Task Management API

A RESTful API for task management with role-based access control and task dependencies.

## Features

- Role-based access (Manager/User permissions)
- Task dependency management with circular dependency prevention
- JWT authentication with Laravel Sanctum
- Comprehensive filtering and pagination
- Docker support for easy deployment

## Quick Setup

### Using Docker (Recommended)

1. **Clone and Setup Environment**
   ```bash
   git clone <repository-url>
   cd TaskManagementAPI
   cp .env.example .env
   ```

2. **Update Database Configuration**
   Edit `.env` file:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=db
   DB_PORT=3306
   DB_DATABASE=task_management_api
   DB_USERNAME=root
   DB_PASSWORD=root_password
   ```

3. **Start Application**
   ```bash
   docker-compose up -d
   docker-compose exec app composer install --ignore-platform-req=ext-fileinfo
   docker-compose exec app php artisan key:generate
   docker-compose exec app php artisan migrate:fresh --seed
   ```

4. **Test Installation**
   ```bash
   docker-compose exec app php artisan test
   ```

The API will be available at `http://localhost:8000/api`

### Manual Setup

1. **Install Dependencies**
   ```bash
   composer install --ignore-platform-req=ext-fileinfo
   cp .env.example .env
   php artisan key:generate
   ```

2. **Configure Database**
   Update `.env` with your MySQL credentials:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=task_management_api
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

3. **Setup Database**
   ```bash
   php artisan migrate:fresh --seed
   php artisan serve
   ```

## API Usage

### Authentication

**Login:**
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"manager@taskapp.com","password":"password123"}'
```

**Use Token:**
```bash
curl -X GET http://localhost:8000/api/tasks \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### Default Users

| Email | Password | Role |
|-------|----------|------|
| manager@taskapp.com | password123 | manager |
| alice@taskapp.com | password123 | user |

### Key Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/login` | User login |
| GET | `/api/tasks` | List tasks |
| POST | `/api/tasks` | Create task (managers only) |
| PUT | `/api/tasks/{id}` | Update task |
| DELETE | `/api/tasks/{id}` | Delete task (managers only) |

## Testing

```bash
# Run all tests
docker-compose exec app php artisan test

# Or without Docker
php artisan test
```

## Requirements

- PHP 8.2+
- MySQL 8.0
- Composer
- Docker & Docker Compose (recommended)

## License

MIT License
