import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import toast from 'react-hot-toast';
import { ArrowLeft, Loader2 } from 'lucide-react';

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
            <label htmlFor="instance_id" className="block text-sm font-medium text-gray-700 mb-2">
              Instance ID *
            </label>
            <input
              id="instance_id"
              type="text"
              value={formData.instance_id}
              onChange={(e) => setFormData({...formData, instance_id: e.target.value})}
              pattern="[a-z0-9-]+"
              required
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
              placeholder="wp-client-001"
            />
            <p className="mt-1 text-sm text-gray-500">
              Lowercase letters, numbers, and hyphens only
            </p>
          </div>

          {/* Instance Name */}
          <div>
            <label htmlFor="instance_name" className="block text-sm font-medium text-gray-700 mb-2">
              Instance Name *
            </label>
            <input
              id="instance_name"
              type="text"
              value={formData.instance_name}
              onChange={(e) => setFormData({...formData, instance_name: e.target.value})}
              required
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
              placeholder="Client Website"
            />
          </div>

          {/* CMS Type */}
          <div>
            <label htmlFor="cms_type" className="block text-sm font-medium text-gray-700 mb-2">
              CMS Type *
            </label>
            <select
              id="cms_type"
              value={formData.cms_type}
              onChange={(e) => setFormData({...formData, cms_type: e.target.value})}
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
            >
              <option value="wordpress">WordPress</option>
              <option value="joomla">Joomla</option>
              <option value="drupal">Drupal</option>
            </select>
          </div>

          {/* Domain */}
          <div>
            <label htmlFor="domain" className="block text-sm font-medium text-gray-700 mb-2">
              Domain *
            </label>
            <input
              id="domain"
              type="text"
              value={formData.domain}
              onChange={(e) => setFormData({...formData, domain: e.target.value})}
              required
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
              placeholder="client.example.com"
            />
          </div>

          {/* Database Name */}
          <div>
            <label htmlFor="database_name" className="block text-sm font-medium text-gray-700 mb-2">
              Database Name *
            </label>
            <input
              id="database_name"
              type="text"
              value={formData.database_name}
              onChange={(e) => setFormData({...formData, database_name: e.target.value})}
              required
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
              placeholder="ikabud_wp_client"
            />
          </div>

          {/* Database Prefix */}
          <div>
            <label htmlFor="database_prefix" className="block text-sm font-medium text-gray-700 mb-2">
              Database Prefix
            </label>
            <input
              id="database_prefix"
              type="text"
              value={formData.database_prefix}
              onChange={(e) => setFormData({...formData, database_prefix: e.target.value})}
              className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
              placeholder="wp_"
            />
          </div>

          {/* Advanced Settings */}
          <details className="border rounded-lg p-4">
            <summary className="cursor-pointer font-medium text-gray-900">
              Advanced Settings
            </summary>
            <div className="mt-4 space-y-4">
              {/* Memory Limit */}
              <div>
                <label htmlFor="memory_limit" className="block text-sm font-medium text-gray-700 mb-2">
                  Memory Limit
                </label>
                <select
                  id="memory_limit"
                  value={formData.memory_limit}
                  onChange={(e) => setFormData({...formData, memory_limit: e.target.value})}
                  className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
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
                <label htmlFor="max_execution_time" className="block text-sm font-medium text-gray-700 mb-2">
                  Max Execution Time (seconds)
                </label>
                <input
                  id="max_execution_time"
                  type="number"
                  value={formData.max_execution_time}
                  onChange={(e) => setFormData({...formData, max_execution_time: parseInt(e.target.value)})}
                  className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>

              {/* Max Children */}
              <div>
                <label htmlFor="max_children" className="block text-sm font-medium text-gray-700 mb-2">
                  Max PHP Workers
                </label>
                <input
                  id="max_children"
                  type="number"
                  value={formData.max_children}
                  onChange={(e) => setFormData({...formData, max_children: parseInt(e.target.value)})}
                  className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
                <p className="mt-1 text-sm text-gray-500">
                  Number of PHP-FPM worker processes
                </p>
              </div>
            </div>
          </details>

          {/* Submit Button */}
          <button
            type="submit"
            disabled={loading}
            className="w-full flex justify-center items-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {loading ? (
              <>
                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                Creating Instance...
              </>
            ) : (
              'Create Instance'
            )}
          </button>
        </form>
      </div>
    </div>
  );
}
