# Task Management API

A RESTful API for task management with role-based access control and task dependencies.

## ✨ Features

- **Role-Based Access Control** - Manager/User permissions
- **Task Dependencies** - Hierarchical tasks with circular dependency prevention
- **JWT Authentication** - Secure token-based authentication with Laravel Sanctum
- **Advanced Filtering** - Filter by status, assignee, and date ranges
- **Docker Ready** - One-command setup with Docker
- **Fully Tested** - 45 automated tests with 100% feature coverage

## 🚀 Quick Start

### One-Command Setup

**Windows:**
```bash
./start.sh
```

**Linux/Mac:**
```bash
chmod +x start.sh
./start.sh
```

*Note: On Windows, make sure to run this in Git Bash (comes with Git for Windows)*

That's it! The script will automatically:
- ✅ Set up environment configuration
- ✅ Start Docker containers (or guide manual setup)
- ✅ Install dependencies
- ✅ Set up database with test data
- ✅ Run tests to verify everything works

### Manual Setup (Alternative)

<details>
<summary>Click to expand manual installation steps</summary>

#### Using Docker
```bash
cp .env.example .env
# Edit .env: set DB_HOST=db, DB_USERNAME=root, DB_PASSWORD=root_password
docker-compose up -d
docker-compose exec app composer install --ignore-platform-req=ext-fileinfo
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate:fresh --seed
```

</details>

## 👤 Default Test Users

| Email | Password | Role | Permissions |
|-------|----------|------|-------------|
| `manager@taskapp.com` | `password123` | Manager | Full access to all features |
| `alice@taskapp.com` | `password123` | User | Can only manage assigned tasks |

## 🔗 API Quick Reference

### Core Endpoints

| Method | Endpoint | Description | Access |
|--------|----------|-------------|---------|
| `POST` | `/api/auth/login` | Login and get JWT token | Public |
| `GET` | `/api/auth/profile` | Get user profile | Authenticated |
| `GET` | `/api/tasks` | List tasks (with filters) | Authenticated |
| `POST` | `/api/tasks` | Create new task | Manager only |
| `PUT` | `/api/tasks/{id}` | Update task | Role-specific |
| `DELETE` | `/api/tasks/{id}` | Delete task | Manager only |
| `POST` | `/api/tasks/{id}/dependencies` | Add task dependency | Manager only |

## 📖 API Documentation

### Database Schema

**Entity Relationship Diagram (ERD)**: View the complete database schema and relationships in [`docs/ERD.pdf`](docs/ERD.pdf)

### Postman Collection

A complete Postman collection is available in the `docs/` folder:

1. **Import Collection**: Import `docs/Task_Management_API.postman_collection.json` into Postman
2. **Test All Endpoints**: The collection includes all API endpoints with proper authentication

### API Endpoints

**Login to get JWT token:**

## 🧪 Testing

```bash
# Run all tests
docker-compose exec app php artisan test
```

**Test Coverage:** 45 tests covering authentication, task management, and dependencies.

## 🛠️ Development Commands

```bash
# View logs
docker-compose logs app

# Access container shell
docker-compose exec app bash

# Stop services
docker-compose down

# Reset database
docker-compose exec app php artisan migrate:fresh --seed
```

## 📋 Requirements

- **Docker & Docker Compose**
