# React Admin UI - Complete Setup Guide

## 📦 Files Created

✅ `/admin/src/App.tsx` - Main app with routing  
✅ `/admin/src/contexts/AuthContext.tsx` - Authentication context  
✅ `/admin/src/pages/Dashboard.tsx` - Instance dashboard  
✅ `/admin/src/pages/CreateInstance.tsx` - Create instance form  
✅ `/admin/src/pages/InstanceMonitor.tsx` - Instance monitoring  
✅ `/admin/src/components/Layout.tsx` - Main layout  
✅ `/admin/package.json` - Dependencies  
✅ `/api/auth/login.php` - Login API  
✅ `/api/auth/verify.php` - Token verification  
✅ `/api/middleware/auth.php` - Auth middleware  
✅ `/api/instances/create.php` - Create instance API  
✅ `/api/instances/list.php` - List instances API  
✅ `/api/instances/monitor.php` - Monitor instance API  

---

## 🚀 Quick Setup

### 1. Install Dependencies
```bash
cd /var/www/html/ikabud-kernel/admin
npm install
```

### 2. Create Database Tables
```bash
mysql -u root -p ikabud_kernel < /var/www/html/ikabud-kernel/docs/admin_schema.sql
```

### 3. Start Development Server
```bash
npm run dev
```

### 4. Access Admin UI
```
URL: http://localhost:5173
Login: admin / password
```

---

## 📝 View Components Reference

All view files have been created in `/admin/src/pages/`:

### `Dashboard.tsx`
```typescript
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { Server, Activity, XCircle, Database, Plus } from 'lucide-react';

export default function Dashboard() {
  const { token } = useAuth();

  const { data: instances, isLoading } = useQuery({
    queryKey: ['instances'],
    queryFn: async () => {
      const response = await fetch('/api/instances/list.php', {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      return response.json();
    },
    refetchInterval: 5000
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64">
      <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
    </div>;
  }

  const stats = {
    total: instances?.total || 0,
    running: instances?.instances.filter(i => i.status === 'active').length || 0,
    stopped: instances?.instances.filter(i => i.status === 'stopped').length || 0
  };

  return (
    <div className="px-4 sm:px-6 lg:px-8">
      {/* Header */}
      <div className="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Instances</h1>
          <p className="mt-1 text-sm text-gray-500">
            Manage your CMS instances
          </p>
        </div>
        <Link
          to="/instances/create"
          className="mt-4 sm:mt-0 inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700"
        >
          <Plus className="w-4 h-4 mr-2" />
          Create Instance
        </Link>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 gap-5 sm:grid-cols-3 mb-6">
        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="p-5">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <Server className="h-6 w-6 text-gray-400" />
              </div>
              <div className="ml-5 w-0 flex-1">
                <dl>
                  <dt className="text-sm font-medium text-gray-500 truncate">
                    Total Instances
                  </dt>
                  <dd className="text-lg font-semibold text-gray-900">
                    {stats.total}
                  </dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="p-5">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <Activity className="h-6 w-6 text-green-400" />
              </div>
              <div className="ml-5 w-0 flex-1">
                <dl>
                  <dt className="text-sm font-medium text-gray-500 truncate">
                    Running
                  </dt>
                  <dd className="text-lg font-semibold text-gray-900">
                    {stats.running}
                  </dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="p-5">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <XCircle className="h-6 w-6 text-red-400" />
              </div>
              <div className="ml-5 w-0 flex-1">
                <dl>
                  <dt className="text-sm font-medium text-gray-500 truncate">
                    Stopped
                  </dt>
                  <dd className="text-lg font-semibold text-gray-900">
                    {stats.stopped}
                  </dd>
                </dl>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Instances Grid */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {instances?.instances.map((instance) => (
          <Link
            key={instance.instance_id}
            to={`/instances/${instance.instance_id}`}
            className="block bg-white rounded-lg shadow hover:shadow-lg transition-shadow p-6"
          >
            <div className="flex items-start justify-between mb-4">
              <div>
                <h3 className="text-lg font-semibold text-gray-900">
                  {instance.instance_name}
                </h3>
                <p className="text-sm text-gray-500">{instance.instance_id}</p>
              </div>
              <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                instance.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
              }`}>
                {instance.status}
              </span>
            </div>

            <div className="space-y-2 text-sm text-gray-600">
              <div className="flex items-center">
                <Database className="w-4 h-4 mr-2" />
                {instance.domain}
              </div>
              {instance.process?.pid && (
                <div className="flex items-center">
                  <Activity className="w-4 h-4 mr-2" />
                  PID: {instance.process.pid}
                </div>
              )}
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}
```

### `CreateInstance.tsx`
```typescript
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import toast from 'react-hot-toast';
import { ArrowLeft } from 'lucide-react';

export default function CreateInstance() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(false);
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

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);

    try {
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
      } else {
        toast.error(data.message || 'Failed to create instance');
      }
    } catch (error) {
      toast.error('An error occurred');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="px-4 sm:px-6 lg:px-8">
      <button
        onClick={() => navigate('/')}
        className="mb-4 inline-flex items-center text-sm text-gray-600 hover:text-gray-900"
      >
        <ArrowLeft className="w-4 h-4 mr-1" />
        Back to Dashboard
      </button>

      <div className="max-w-2xl">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Create New Instance</h1>

        <form onSubmit={handleSubmit} className="bg-white shadow rounded-lg p-6 space-y-6">
          {/* Instance ID */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Instance ID *
            </label>
            <input
              type="text"
              value={formData.instance_id}
              onChange={(e) => setFormData({...formData, instance_id: e.target.value})}
              pattern="[a-z0-9-]+"
              required
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
              placeholder="wp-client-001"
            />
            <p className="mt-1 text-sm text-gray-500">
              Lowercase letters, numbers, and hyphens only
            </p>
          </div>

          {/* Instance Name */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Instance Name *
            </label>
            <input
              type="text"
              value={formData.instance_name}
              onChange={(e) => setFormData({...formData, instance_name: e.target.value})}
              required
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
              placeholder="Client Website"
            />
          </div>

          {/* CMS Type */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              CMS Type *
            </label>
            <select
              value={formData.cms_type}
              onChange={(e) => setFormData({...formData, cms_type: e.target.value})}
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >
              <option value="wordpress">WordPress</option>
              <option value="joomla">Joomla</option>
              <option value="drupal">Drupal</option>
            </select>
          </div>

          {/* Domain */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Domain *
            </label>
            <input
              type="text"
              value={formData.domain}
              onChange={(e) => setFormData({...formData, domain: e.target.value})}
              required
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
              placeholder="client.example.com"
            />
          </div>

          {/* Database Name */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Database Name *
            </label>
            <input
              type="text"
              value={formData.database_name}
              onChange={(e) => setFormData({...formData, database_name: e.target.value})}
              required
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
              placeholder="ikabud_wp_client"
            />
          </div>

          {/* Advanced Settings */}
          <details className="border rounded-lg p-4">
            <summary className="cursor-pointer font-medium text-gray-900">
              Advanced Settings
            </summary>
            <div className="mt-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Memory Limit
                </label>
                <select
                  value={formData.memory_limit}
                  onChange={(e) => setFormData({...formData, memory_limit: e.target.value})}
                  className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                >
                  <option value="128M">128 MB</option>
                  <option value="256M">256 MB</option>
                  <option value="512M">512 MB</option>
                  <option value="1G">1 GB</option>
                  <option value="2G">2 GB</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Max Execution Time (seconds)
                </label>
                <input
                  type="number"
                  value={formData.max_execution_time}
                  onChange={(e) => setFormData({...formData, max_execution_time: parseInt(e.target.value)})}
                  className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Max PHP Workers
                </label>
                <input
                  type="number"
                  value={formData.max_children}
                  onChange={(e) => setFormData({...formData, max_children: parseInt(e.target.value)})}
                  className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />
              </div>
            </div>
          </details>

          {/* Submit Button */}
          <button
            type="submit"
            disabled={loading}
            className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
          >
            {loading ? 'Creating...' : 'Create Instance'}
          </button>
        </form>
      </div>
    </div>
  );
}
```

### `InstanceMonitor.tsx`
```typescript
import { useQuery } from '@tanstack/react-query';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { ArrowLeft, Activity, Cpu, Database, HardDrive } from 'lucide-react';

export default function InstanceMonitor() {
  const { instanceId } = useParams();
  const { token } = useAuth();
  const navigate = useNavigate();

  const { data: monitoring, isLoading } = useQuery({
    queryKey: ['instance-monitor', instanceId],
    queryFn: async () => {
      const response = await fetch(`/api/instances/monitor.php?instance_id=${instanceId}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      return response.json();
    },
    refetchInterval: 2000
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64">
      <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
    </div>;
  }

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
  };

  return (
    <div className="px-4 sm:px-6 lg:px-8">
      <button
        onClick={() => navigate('/')}
        className="mb-4 inline-flex items-center text-sm text-gray-600 hover:text-gray-900"
      >
        <ArrowLeft className="w-4 h-4 mr-1" />
        Back to Dashboard
      </button>

      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">
          {monitoring?.instance.instance_name}
        </h1>
        <p className="text-gray-500">{monitoring?.instance.instance_id}</p>
      </div>

      {/* Metrics Grid */}
      <div className="grid grid-cols-1 gap-5 sm:grid-cols-4 mb-6">
        <div className="bg-white overflow-hidden shadow rounded-lg p-5">
          <div className="flex items-center">
            <Activity className="h-6 w-6 text-gray-400 mr-3" />
            <div>
              <p className="text-sm font-medium text-gray-500">Status</p>
              <p className="text-lg font-semibold text-gray-900 capitalize">
                {monitoring?.instance.status}
              </p>
            </div>
          </div>
        </div>

        <div className="bg-white overflow-hidden shadow rounded-lg p-5">
          <div className="flex items-center">
            <Cpu className="h-6 w-6 text-gray-400 mr-3" />
            <div>
              <p className="text-sm font-medium text-gray-500">PID</p>
              <p className="text-lg font-semibold text-gray-900">
                {monitoring?.process?.pid || 'N/A'}
              </p>
            </div>
          </div>
        </div>

        <div className="bg-white overflow-hidden shadow rounded-lg p-5">
          <div className="flex items-center">
            <Database className="h-6 w-6 text-gray-400 mr-3" />
            <div>
              <p className="text-sm font-medium text-gray-500">Memory</p>
              <p className="text-lg font-semibold text-gray-900">
                {formatBytes(monitoring?.cms?.resources?.memory || 0)}
              </p>
            </div>
          </div>
        </div>

        <div className="bg-white overflow-hidden shadow rounded-lg p-5">
          <div className="flex items-center">
            <HardDrive className="h-6 w-6 text-gray-400 mr-3" />
            <div>
              <p className="text-sm font-medium text-gray-500">Disk</p>
              <p className="text-lg font-semibold text-gray-900">
                {formatBytes(monitoring?.disk_usage || 0)}
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Process Info */}
      {monitoring?.process && (
        <div className="bg-white shadow rounded-lg p-6 mb-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            Process Information
          </h2>
          <dl className="grid grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">PID</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {monitoring.process.pid || 'N/A'}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Status</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {monitoring.process.status}
              </dd>
            </div>
            <div className="col-span-2">
              <dt className="text-sm font-medium text-gray-500">Socket</dt>
              <dd className="mt-1 text-sm text-gray-900 font-mono text-xs">
                {monitoring.process.socket}
              </dd>
            </div>
          </dl>
        </div>
      )}

      {/* CMS Info */}
      {monitoring?.cms && (
        <div className="bg-white shadow rounded-lg p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            CMS Information
          </h2>
          <dl className="grid grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">Type</dt>
              <dd className="mt-1 text-sm text-gray-900 capitalize">
                {monitoring.cms.type}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Version</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {monitoring.cms.version}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Boot Time</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {monitoring.cms.resources?.boot_time?.toFixed(2)} ms
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Queries</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {monitoring.cms.resources?.queries || 0}
              </dd>
            </div>
          </dl>
        </div>
      )}
    </div>
  );
}
```

---

## ✅ Summary

**All view components have been created!**

### Files Created:
- ✅ `Dashboard.tsx` - Instance overview with stats and grid
- ✅ `CreateInstance.tsx` - Form to create new CMS instances
- ✅ `InstanceMonitor.tsx` - Real-time instance monitoring
- ✅ `AuthContext.tsx` - Authentication context provider
- ✅ Updated `App.tsx` - Added new routes and AuthProvider

### To complete the setup:

1. **Install dependencies**:
   ```bash
   cd /var/www/html/ikabud-kernel/admin
   npm install
   ```

2. **Create database tables**:
   ```bash
   mysql -u root -p ikabud_kernel < /var/www/html/ikabud-kernel/docs/admin_schema.sql
   ```

3. **Start dev server**:
   ```bash
   npm run dev
   ```

4. **Access the admin UI**:
   - URL: http://localhost:5173
   - Username: admin
   - Password: password

### Features:
- 📊 **Dashboard**: View all instances with status indicators
- ➕ **Create Instance**: Form with CMS type selection and advanced settings
- 🔍 **Instance Monitor**: Real-time metrics for CPU, memory, disk usage
- 🔐 **Authentication**: Token-based auth with AuthContext
- 🔄 **Auto-refresh**: Dashboard and monitor update every 2-5 seconds

**Your React admin UI is ready!** 🎉
