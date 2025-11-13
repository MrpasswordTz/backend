# Mdukuzi AI Backend API

A comprehensive Laravel 12 backend API for the Mdukuzi AI chat application. This backend provides user authentication, AI chat functionality powered by HuggingFace, comprehensive admin dashboard, security features, and system management tools.

## Features

### Core Functionality
- **User Authentication**: Registration, login, logout with Laravel Sanctum
- **AI Chat**: Integration with HuggingFace API (DeepHat model) for chat completions
- **Chat History**: Persistent chat sessions and message history
- **Contact Form**: Public contact submission system

### Admin Dashboard
- **User Management**: CRUD operations for users
- **Chat Management**: View, moderate, flag, and delete chats with analytics
- **Contact Submissions**: Manage and reply to contact form submissions
- **Audit Logging**: Comprehensive activity tracking
- **API Management**: Configure and monitor API usage with analytics
- **Backup & Restore**: Database backup creation, restoration, and management
- **Activity Logs**: Login history, active sessions, and user activity tracking
- **System Health**: Monitor system status and error logs

### Security Features
- **IP Banning**: Ban/unban IP addresses
- **IP Whitelisting**: Configure allowed IP addresses
- **Failed Login Tracking**: Monitor and track failed login attempts
- **Maintenance Mode**: Enable/disable maintenance mode with IP exceptions
- **Security Settings**: Configurable security parameters
- **Session Management**: Force logout active sessions

### System Management
- **Cache Management**: Clear cache and optimize database
- **Error Log Management**: View and clear error logs
- **Database Optimization**: Database performance optimization tools

## Requirements

- PHP >= 8.2
- Composer
- Node.js and npm
- SQLite (default) or MySQL/PostgreSQL
- HuggingFace API Token (HF_TOKEN)

## Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd backend
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install Node.js dependencies**
   ```bash
   npm install
   ```

4. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Configure environment variables**
   Edit `.env` and set:
   ```env
   HF_TOKEN=your_huggingface_token_here
   DB_CONNECTION=sqlite
   # Or configure MySQL/PostgreSQL if preferred
   ```

6. **Run migrations**
   ```bash
   php artisan migrate
   ```

7. **Build frontend assets** (if applicable)
   ```bash
   npm run build
   ```

## Quick Start

Use the provided setup script to get started quickly:

```bash
composer run setup
```

This will:
- Install Composer dependencies
- Copy `.env.example` to `.env` if it doesn't exist
- Generate application key
- Run database migrations
- Install npm dependencies
- Build frontend assets

## Development

Start the development server with all services:

```bash
composer run dev
```

This command runs:
- Laravel development server
- Queue worker
- Laravel Pail (log viewer)
- Vite development server

## API Documentation

### Base URL
```
http://localhost:8000/api
```

### Authentication

All protected routes require a Bearer token in the Authorization header:
```
Authorization: Bearer {token}
```

### Public Endpoints

#### Register User
```http
POST /api/register
Content-Type: application/json

{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

#### Login
```http
POST /api/login
Content-Type: application/json

{
  "email": "john@example.com",
  "password": "password123"
}
```

#### Submit Contact Form
```http
POST /api/contact
Content-Type: application/json

{
  "name": "John Doe",
  "email": "john@example.com",
  "subject": "Inquiry",
  "message": "Your message here"
}
```

### Protected Endpoints (Requires Authentication)

#### Get Current User
```http
GET /api/me
Authorization: Bearer {token}
```

#### Logout
```http
POST /api/logout
Authorization: Bearer {token}
```

#### Send Chat Message
```http
POST /api/chat/message
Authorization: Bearer {token}
Content-Type: application/json

{
  "message": "What is the capital of France?",
  "session_id": "optional-session-id"
}
```

#### Get Chat History
```http
GET /api/chat/history?session_id={session_id}
Authorization: Bearer {token}
```

#### Get Chat Sessions
```http
GET /api/chat/sessions
Authorization: Bearer {token}
```

#### Delete Chat History
```http
DELETE /api/chat/history/{id}
Authorization: Bearer {token}
```

#### Update User Profile
```http
PUT /api/user/profile
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Updated Name",
  "email": "updated@example.com"
}
```

### Admin Endpoints (Requires Admin Role)

All admin endpoints are prefixed with `/api/admin` and require both authentication and admin privileges.

#### Dashboard Statistics
```http
GET /api/admin/dashboard/stats
```

#### User Management
- `GET /api/admin/users` - List all users
- `POST /api/admin/users` - Create user
- `GET /api/admin/users/{id}` - Get user details
- `PUT /api/admin/users/{id}` - Update user
- `DELETE /api/admin/users/{id}` - Delete user

#### Chat Management
- `GET /api/admin/chats` - List all chats
- `GET /api/admin/chats/analytics` - Get chat analytics
- `GET /api/admin/chats/{id}` - Get chat details
- `DELETE /api/admin/chats/{id}` - Delete chat
- `POST /api/admin/chats/bulk-delete` - Bulk delete chats
- `POST /api/admin/chats/{id}/flag` - Flag chat for review
- `POST /api/admin/chats/{id}/unflag` - Unflag chat
- `POST /api/admin/chats/{id}/review` - Review chat
- `GET /api/admin/chats/export/csv` - Export chats to CSV

#### Security Management
- `GET /api/admin/banned-ips` - List banned IPs
- `POST /api/admin/banned-ips` - Ban IP address
- `DELETE /api/admin/banned-ips/{id}` - Unban IP address
- `GET /api/admin/security-settings` - Get security settings
- `PUT /api/admin/security-settings` - Update security settings
- `GET /api/admin/security-settings/ip-whitelist` - Get IP whitelist
- `POST /api/admin/security-settings/ip-whitelist` - Add IP to whitelist
- `DELETE /api/admin/security-settings/ip-whitelist/{id}` - Remove IP from whitelist

#### System Management
- `GET /api/admin/system-health` - Get system health status
- `GET /api/admin/error-logs` - Get error logs
- `DELETE /api/admin/error-logs` - Clear error logs
- `GET /api/admin/cache/stats` - Get cache statistics
- `POST /api/admin/cache/clear` - Clear cache
- `POST /api/admin/cache/optimize-database` - Optimize database

#### Maintenance Mode
- `GET /api/admin/maintenance-mode` - Get maintenance mode status
- `PUT /api/admin/maintenance-mode` - Update maintenance mode
- `POST /api/admin/maintenance-mode/allowed-ips` - Add allowed IP
- `DELETE /api/admin/maintenance-mode/allowed-ips/{id}` - Remove allowed IP

#### Backup & Restore
- `GET /api/admin/backups` - List backups
- `POST /api/admin/backups` - Create backup
- `POST /api/admin/backups/{id}/restore` - Restore from backup
- `GET /api/admin/backups/{id}/download` - Download backup
- `DELETE /api/admin/backups/{id}` - Delete backup

#### Activity Logs
- `GET /api/admin/activity/login-history` - Get login history
- `GET /api/admin/activity/active-sessions` - Get active sessions
- `POST /api/admin/activity/sessions/{id}/force-logout` - Force logout session
- `GET /api/admin/activity/user-activity` - Get user activity
- `GET /api/admin/activity/failed-logins` - Get failed login attempts

#### API Management
- `GET /api/admin/api/analytics` - Get API usage analytics
- `GET /api/admin/api/config` - Get API configuration
- `PUT /api/admin/api/config` - Update API configuration
- `DELETE /api/admin/api/config/{key}` - Delete API configuration
- `GET /api/admin/api/usage-logs` - Get API usage logs
- `GET /api/admin/api/usage-logs/export/csv` - Export usage logs

#### Contact Submissions
- `GET /api/admin/contact-submissions` - List submissions
- `GET /api/admin/contact-submissions/{id}` - Get submission
- `PUT /api/admin/contact-submissions/{id}/read` - Mark as read/unread
- `POST /api/admin/contact-submissions/{id}/reply` - Reply to submission
- `DELETE /api/admin/contact-submissions/{id}` - Delete submission
- `GET /api/admin/contact-submissions/export/csv` - Export to CSV

#### Audit Logs
- `GET /api/admin/audit-logs` - Get audit logs
- `DELETE /api/admin/audit-logs` - Delete all audit logs

### API Info Endpoint

Get information about all available endpoints:

```http
GET /api/
```

## Testing

Run the test suite:

```bash
composer run test
```

Or use PHPUnit directly:

```bash
php artisan test
```

## Code Style

This project uses Laravel Pint for code formatting:

```bash
./vendor/bin/pint
```

## Project Structure

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/       # API controllers
│   │   │   ├── Admin/         # Admin-specific controllers
│   │   │   └── ...
│   │   └── Middleware/        # Custom middleware
│   ├── Models/                # Eloquent models
│   └── Providers/            # Service providers
├── config/                    # Configuration files
├── database/
│   ├── migrations/           # Database migrations
│   └── seeders/             # Database seeders
├── routes/
│   ├── api.php              # API routes
│   └── web.php             # Web routes
├── storage/                 # Storage directory
├── tests/                   # Test files
└── public/                 # Public assets
```

## Environment Variables

Key environment variables:

```env
APP_NAME=Mdukuzi AI
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost

HF_TOKEN=your_huggingface_token

DB_CONNECTION=sqlite
# Or for MySQL/PostgreSQL:
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=your_database
# DB_USERNAME=your_username
# DB_PASSWORD=your_password
```

## HuggingFace API Integration

This backend integrates with HuggingFace API for AI chat functionality. The integration uses the DeepHat model (`DeepHat/DeepHat-V1-7B:featherless-ai`).

Example usage is provided in `api_usage.php`:

```php
php api_usage.php
```

Make sure to set your `HF_TOKEN` in the `.env` file before using the chat functionality.

## Security Considerations

- All passwords are hashed using bcrypt
- API tokens are managed via Laravel Sanctum
- IP banning and whitelisting capabilities
- Failed login attempt tracking
- Maintenance mode support
- Comprehensive audit logging
- CSRF protection enabled

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Support

For issues, questions, or contributions, please open an issue on the repository.
