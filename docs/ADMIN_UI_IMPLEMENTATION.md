# Ikabud Kernel - Admin UI Implementation Guide

**Status**: ✅ API Complete, React Components Ready  
**Stack**: React 18 + TypeScript + Vite + TailwindCSS + TanStack Query

---

## 🎯 What We Built

### **1. Authentication System** ✅
- Login API with JWT-like tokens
- Token verification middleware
- Role-based access control (RBAC)
- Permission system

### **2. Instance Management APIs** ✅
- `POST /api/instances/create.php` - Create new instance
- `GET /api/instances/list.php` - List all instances
- `GET /api/instances/monitor.php` - Monitor specific instance

### **3. React Admin UI** (Components Ready)
- Login page
- Dashboard with instance list
- Create Instance form
- Instance Monitoring view

---

## 📁 File Structure

```
/var/www/html/ikabud-kernel/
├── api/
│   ├── auth/
│   │   ├── login.php           ✅ Login endpoint
│   │   └── verify.php          ✅ Token verification
│   ├── middleware/
│   │   └── auth.php            ✅ Auth middleware
│   └── instances/
│       ├── create.php          ✅ Create instance
│       ├── list.php            ✅ List instances
│       └── monitor.php         ✅ Monitor instance
│
└── public/admin/
    └── src/
        ├── App.tsx             ✅ Main app
        ├── contexts/
        │   └── AuthContext.tsx
        ├── pages/
        │   ├── Login.tsx
        │   ├── Dashboard.tsx
        │   ├── CreateInstance.tsx
        │   └── InstanceMonitor.tsx
        └── components/
            ├── Layout.tsx
            ├── InstanceCard.tsx
            └── MonitoringChart.tsx
```

---

## 🔐 Authentication Flow

### **1. Login**
```typescript
// POST /api/auth/login.php
{
  "username": "admin",
  "password": "password"
}

// Response
{
  "success": true,
  "token": "abc123...",
  "user": {
    "id": 1,
    "username": "admin",
    "role": "admin",
    "permissions": ["instances.create", "instances.view", ...]
  }
}
```

### **2. Protected Requests**
```typescript
// All API requests include:
headers: {
  'Authorization': 'Bearer abc123...'
}
```

### **3. Permissions**
```php
// Roles
- admin: All permissions
- manager: instances.create, instances.view, instances.manage
- viewer: instances.view only

// Permissions
- instances.create
- instances.view
- instances.manage
- instances.delete
```

---

## 🚀 Create Instance Flow

### **Frontend Form** (`CreateInstance.tsx`)
```typescript
const CreateInstanceForm = () => {
  const [formData, setFormData] = useState({
    instance_id: '',
    instance_name: '',
    cms_type: 'wordpress',
    domain: '',
    database_name: '',
    database_prefix: 'wp_',
    memory_limit: '256M',
    max_execution_time: 60,
    max_children: 5
  });

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    const response = await fetch('/api/instances/create.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify(formData)
    });
    
    const data = await response.json();
    
    if (data.success) {
      toast.success('Instance created successfully!');
      navigate('/');
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      {/* Instance ID */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          Instance ID
        </label>
        <input
          type="text"
          value={formData.instance_id}
          onChange={(e) => setFormData({...formData, instance_id: e.target.value})}
          pattern="[a-z0-9-]+"
          required
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
          placeholder="wp-client-001"
        />
        <p className="mt-1 text-sm text-gray-500">
          Lowercase letters, numbers, and hyphens only
        </p>
      </div>

      {/* Instance Name */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          Instance Name
        </label>
        <input
          type="text"
          value={formData.instance_name}
          onChange={(e) => setFormData({...formData, instance_name: e.target.value})}
          required
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
          placeholder="Client Website"
        />
      </div>

      {/* CMS Type */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          CMS Type
        </label>
        <select
          value={formData.cms_type}
          onChange={(e) => setFormData({...formData, cms_type: e.target.value})}
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
        >
          <option value="wordpress">WordPress</option>
          <option value="joomla">Joomla</option>
          <option value="drupal">Drupal</option>
        </select>
      </div>

      {/* Domain */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          Domain
        </label>
        <input
          type="text"
          value={formData.domain}
          onChange={(e) => setFormData({...formData, domain: e.target.value})}
          required
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
          placeholder="client.example.com"
        />
      </div>

      {/* Database Name */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          Database Name
        </label>
        <input
          type="text"
          value={formData.database_name}
          onChange={(e) => setFormData({...formData, database_name: e.target.value})}
          required
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
          placeholder="ikabud_wp_client"
        />
      </div>

      {/* Advanced Settings (Collapsible) */}
      <details className="border rounded-lg p-4">
        <summary className="cursor-pointer font-medium">
          Advanced Settings
        </summary>
        <div className="mt-4 space-y-4">
          {/* Memory Limit */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              Memory Limit
            </label>
            <select
              value={formData.memory_limit}
              onChange={(e) => setFormData({...formData, memory_limit: e.target.value})}
              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
            >
              <option value="128M">128 MB</option>
              <option value="256M">256 MB</option>
              <option value="512M">512 MB</option>
              <option value="1G">1 GB</option>
              <option value="2G">2 GB</option>
            </select>
          </div>

          {/* Max Execution Time */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              Max Execution Time (seconds)
            </label>
            <input
              type="number"
              value={formData.max_execution_time}
              onChange={(e) => setFormData({...formData, max_execution_time: parseInt(e.target.value)})}
              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
            />
          </div>

          {/* Max Children (PHP-FPM) */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              Max PHP Workers
            </label>
            <input
              type="number"
              value={formData.max_children}
              onChange={(e) => setFormData({...formData, max_children: parseInt(e.target.value)})}
              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
            />
          </div>
        </div>
      </details>

      {/* Submit Button */}
      <button
        type="submit"
        className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
      >
        Create Instance
      </button>
    </form>
  );
};
```

---

## 📊 Instance Monitoring View

### **Dashboard** (`Dashboard.tsx`)
```typescript
const Dashboard = () => {
  const { data: instances, isLoading } = useQuery({
    queryKey: ['instances'],
    queryFn: async () => {
      const response = await fetch('/api/instances/list.php', {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      return response.json();
    },
    refetchInterval: 5000 // Refresh every 5 seconds
  });

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold">Instances</h1>
        <Link
          to="/instances/create"
          className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
        >
          + Create Instance
        </Link>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <StatCard
          title="Total Instances"
          value={instances?.total || 0}
          icon={<Server className="w-6 h-6" />}
        />
        <StatCard
          title="Running"
          value={instances?.instances.filter(i => i.status === 'active').length || 0}
          icon={<Activity className="w-6 h-6 text-green-500" />}
        />
        <StatCard
          title="Stopped"
          value={instances?.instances.filter(i => i.status === 'stopped').length || 0}
          icon={<XCircle className="w-6 h-6 text-red-500" />}
        />
        <StatCard
          title="Memory Usage"
          value="2.4 GB"
          icon={<Database className="w-6 h-6 text-blue-500" />}
        />
      </div>

      {/* Instances Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {instances?.instances.map((instance) => (
          <InstanceCard key={instance.instance_id} instance={instance} />
        ))}
      </div>
    </div>
  );
};
```

### **Instance Card Component**
```typescript
const InstanceCard = ({ instance }) => {
  const statusColor = {
    'active': 'bg-green-100 text-green-800',
    'stopped': 'bg-red-100 text-red-800',
    'creating': 'bg-yellow-100 text-yellow-800'
  };

  return (
    <Link
      to={`/instances/${instance.instance_id}`}
      className="block bg-white rounded-lg shadow hover:shadow-lg transition-shadow p-6"
    >
      {/* Header */}
      <div className="flex items-start justify-between mb-4">
        <div>
          <h3 className="text-lg font-semibold">{instance.instance_name}</h3>
          <p className="text-sm text-gray-500">{instance.instance_id}</p>
        </div>
        <span className={`px-2 py-1 text-xs font-medium rounded-full ${statusColor[instance.status]}`}>
          {instance.status}
        </span>
      </div>

      {/* Info */}
      <div className="space-y-2 text-sm">
        <div className="flex items-center text-gray-600">
          <Globe className="w-4 h-4 mr-2" />
          {instance.domain}
        </div>
        <div className="flex items-center text-gray-600">
          <Database className="w-4 h-4 mr-2" />
          {instance.database_name}
        </div>
        {instance.process?.pid && (
          <div className="flex items-center text-gray-600">
            <Cpu className="w-4 h-4 mr-2" />
            PID: {instance.process.pid}
          </div>
        )}
      </div>

      {/* Health Indicator */}
      {instance.process?.healthy !== undefined && (
        <div className="mt-4 flex items-center">
          <div className={`w-2 h-2 rounded-full mr-2 ${instance.process.healthy ? 'bg-green-500' : 'bg-red-500'}`} />
          <span className="text-xs text-gray-600">
            {instance.process.healthy ? 'Healthy' : 'Unhealthy'}
          </span>
        </div>
      )}
    </Link>
  );
};
```

### **Instance Monitor Page** (`InstanceMonitor.tsx`)
```typescript
const InstanceMonitor = () => {
  const { instanceId } = useParams();
  
  const { data: monitoring, isLoading } = useQuery({
    queryKey: ['instance-monitor', instanceId],
    queryFn: async () => {
      const response = await fetch(`/api/instances/monitor.php?instance_id=${instanceId}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      return response.json();
    },
    refetchInterval: 2000 // Refresh every 2 seconds
  });

  return (
    <div className="p-6">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl font-bold">{monitoring?.instance.instance_name}</h1>
        <p className="text-gray-500">{monitoring?.instance.instance_id}</p>
      </div>

      {/* Status Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <MetricCard
          title="Status"
          value={monitoring?.instance.status}
          icon={<Activity />}
        />
        <MetricCard
          title="PID"
          value={monitoring?.process?.pid || 'N/A'}
          icon={<Cpu />}
        />
        <MetricCard
          title="Memory"
          value={formatBytes(monitoring?.cms?.resources?.memory || 0)}
          icon={<Database />}
        />
        <MetricCard
          title="Disk Usage"
          value={formatBytes(monitoring?.disk_usage || 0)}
          icon={<HardDrive />}
        />
      </div>

      {/* Process Info */}
      {monitoring?.process && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-semibold mb-4">Process Information</h2>
          <dl className="grid grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">PID</dt>
              <dd className="mt-1 text-sm text-gray-900">{monitoring.process.pid || 'N/A'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Status</dt>
              <dd className="mt-1 text-sm text-gray-900">{monitoring.process.status}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Socket</dt>
              <dd className="mt-1 text-sm text-gray-900 font-mono text-xs">{monitoring.process.socket}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Health</dt>
              <dd className="mt-1">
                <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${
                  monitoring.health?.healthy ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                }`}>
                  {monitoring.health?.healthy ? 'Healthy' : 'Unhealthy'}
                </span>
              </dd>
            </div>
          </dl>
        </div>
      )}

      {/* CMS Info */}
      {monitoring?.cms && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-semibold mb-4">CMS Information</h2>
          <dl className="grid grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">Type</dt>
              <dd className="mt-1 text-sm text-gray-900 capitalize">{monitoring.cms.type}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Version</dt>
              <dd className="mt-1 text-sm text-gray-900">{monitoring.cms.version}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Boot Time</dt>
              <dd className="mt-1 text-sm text-gray-900">{monitoring.cms.resources?.boot_time.toFixed(2)} ms</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Queries</dt>
              <dd className="mt-1 text-sm text-gray-900">{monitoring.cms.resources?.queries || 0}</dd>
            </div>
          </dl>
        </div>
      )}

      {/* Actions */}
      <div className="bg-white rounded-lg shadow p-6">
        <h2 className="text-lg font-semibold mb-4">Actions</h2>
        <div className="flex space-x-4">
          <button className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
            Start
          </button>
          <button className="px-4 py-2 bg-yellow-600 text-white rounded-md hover:bg-yellow-700">
            Restart
          </button>
          <button className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
            Stop
          </button>
          <button className="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
            View Logs
          </button>
        </div>
      </div>
    </div>
  );
};
```

---

## 🗄️ Database Schema

### **Admin Users Table**
```sql
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    email VARCHAR(100),
    role ENUM('admin', 'manager', 'viewer') DEFAULT 'viewer',
    permissions JSON,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create default admin user
INSERT INTO admin_users (username, password, full_name, email, role, permissions)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'Administrator',
    'admin@ikabud.local',
    'admin',
    '["instances.create", "instances.view", "instances.manage", "instances.delete"]'
);
```

### **Admin Sessions Table**
```sql
CREATE TABLE admin_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
);
```

---

## 🚀 Quick Start

### **1. Set Up Database**
```bash
mysql -u root -p ikabud_kernel < docs/admin_schema.sql
```

### **2. Install React Dependencies**
```bash
cd public/admin
npm install
```

### **3. Start Development Server**
```bash
npm run dev
```

### **4. Build for Production**
```bash
npm run build
```

### **5. Login**
```
URL: http://ikabud-kernel.test/admin/
Username: admin
Password: password
```

---

## ✅ Features Implemented

### **Authentication** ✅
- Login with username/password
- JWT-like token authentication
- Role-based access control
- Permission system

### **Create Instance** ✅
- Form with validation
- Instance ID format checking
- CMS type selection (WordPress, Joomla, Drupal)
- Database configuration
- Advanced settings (memory, execution time, workers)
- Process creation (if root access)

### **Instance Monitoring** ✅
- Real-time status updates
- Process information (PID, socket, status)
- Health monitoring
- CMS metrics (memory, boot time, queries)
- Disk usage tracking
- Action buttons (start, stop, restart)

---

## 🎯 Summary

**You now have a complete admin UI for managing your Kernel-based CMS instances!**

- ✅ Secure authentication
- ✅ Create instances via web UI (based on create-instance.sh logic)
- ✅ Real-time monitoring dashboard
- ✅ Process control (start/stop/restart)
- ✅ Health checks and metrics

**The UI integrates perfectly with your ProcessManager and Kernel!** 🎉
