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
    admin_subdomain: '',
    database_name: '',
    database_user: 'root',
    database_password: '',
    database_host: 'localhost',
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

      <div className="max-w-4xl mx-auto">
        <h1 className="text-3xl font-bold text-gray-900 mb-8">Create New Instance</h1>

        <form onSubmit={handleSubmit} className="bg-white shadow-sm border border-gray-200 rounded-md p-8 space-y-6">
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
              className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
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
              className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
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
              className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
            >
              <option value="wordpress">WordPress</option>
              <option value="joomla">Joomla</option>
              <option value="drupal">Drupal</option>
            </select>
          </div>

          {/* Domain Configuration */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Frontend Domain *
              </label>
              <input
                type="text"
                value={formData.domain}
                onChange={(e) => setFormData({...formData, domain: e.target.value})}
                required
                className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
                placeholder="mysite.com"
              />
              <p className="mt-1 text-xs text-gray-500">
                Main domain for your website (cached through kernel)
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Admin Subdomain *
              </label>
              <input
                type="text"
                value={formData.admin_subdomain}
                onChange={(e) => setFormData({...formData, admin_subdomain: e.target.value})}
                required
                className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
                placeholder="admin.mysite.com"
              />
              <p className="mt-1 text-xs text-gray-500">
                Subdomain for admin access (direct to WordPress)
              </p>
            </div>
          </div>

          {/* Database Configuration */}
          <div className="space-y-4 border-t pt-6">
            <h3 className="text-lg font-medium text-gray-900">Database Configuration</h3>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Database Name *
                </label>
                <input
                  type="text"
                  value={formData.database_name}
                  onChange={(e) => setFormData({...formData, database_name: e.target.value})}
                  required
                  className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
                  placeholder="ikabud_wp_client"
                />
                <p className="mt-1 text-xs text-gray-500">
                  Database must already exist
                </p>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Database User *
                </label>
                <input
                  type="text"
                  value={formData.database_user}
                  onChange={(e) => setFormData({...formData, database_user: e.target.value})}
                  required
                  className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
                  placeholder="root"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Database Password *
                </label>
                <input
                  type="password"
                  value={formData.database_password}
                  onChange={(e) => setFormData({...formData, database_password: e.target.value})}
                  required
                  className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
                  placeholder="••••••••"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Database Host
                </label>
                <input
                  type="text"
                  value={formData.database_host}
                  onChange={(e) => setFormData({...formData, database_host: e.target.value})}
                  className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
                  placeholder="localhost"
                />
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Table Prefix
              </label>
              <input
                type="text"
                value={formData.database_prefix}
                onChange={(e) => setFormData({...formData, database_prefix: e.target.value})}
                className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
                placeholder="wp_"
              />
              <p className="mt-1 text-xs text-gray-500">
                Default: wp_
              </p>
            </div>
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
                  className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
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
                  className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
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
                  className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
                />
              </div>
            </div>
          </details>

          {/* Submit Button */}
          <div className="flex gap-4 pt-4">
            <button
              type="button"
              onClick={() => navigate('/')}
              className="flex-1 px-6 py-3 border border-gray-300 rounded text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading}
              className="flex-1 px-6 py-3 border border-transparent rounded text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {loading ? 'Creating Instance...' : 'Create Instance'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
