import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import toast from 'react-hot-toast';
import { ArrowLeft, CheckCircle, XCircle } from 'lucide-react';

export default function CreateInstance() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(false);
  const [isInstanceIdManuallyEdited, setIsInstanceIdManuallyEdited] = useState(false);
  const [formData, setFormData] = useState({
    instance_id: '',
    instance_name: '',
    cms_type: '',
    domain: '',
    admin_subdomain: '',
    database_name: '',
    database_user: 'root',
    database_password: '',
    database_host: 'localhost',
    database_prefix: '',
    memory_limit: '256M',
    max_execution_time: 60,
    max_children: 5
  });

  // Generate slug from instance name
  const generateSlug = (name: string) => {
    if (!name) return '';
    return name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')  // Replace non-alphanumeric with hyphens
      .replace(/^-+|-+$/g, '')      // Remove leading/trailing hyphens
      .substring(0, 32);            // Limit length
  };

  // Get CMS-specific settings
  const getCMSDefaults = (cmsType: string) => {
    switch (cmsType) {
      case 'wordpress':
        return { prefix: 'wp_', contentDir: 'wp-content', script: 'create-instance.sh', idPrefix: 'wp' };
      case 'joomla':
        return { prefix: 'jml_', contentDir: 'administrator', script: 'create-joomla-instance.sh', idPrefix: 'jml' };
      case 'drupal':
        return { prefix: 'drupal_', contentDir: 'sites', script: 'create-drupal-instance.sh', idPrefix: 'dpl' };
      default:
        return { prefix: '', contentDir: '', script: '', idPrefix: 'inst' };
    }
  };

  // Validate instance ID format
  const isInstanceIdValid = (id: string) => {
    if (!id) return false;
    return /^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(id) && id.length >= 3 && id.length <= 50;
  };

  // Auto-generate instance ID from instance name
  useEffect(() => {
    if (!isInstanceIdManuallyEdited && formData.instance_name && formData.cms_type) {
      const slug = generateSlug(formData.instance_name);
      const cmsPrefix = getCMSDefaults(formData.cms_type).idPrefix;
      const generatedId = slug ? `${cmsPrefix}-${slug}` : '';
      
      setFormData(prev => ({
        ...prev,
        instance_id: generatedId
      }));
    }
  }, [formData.instance_name, formData.cms_type, isInstanceIdManuallyEdited]);

  // Handle manual instance ID changes
  const handleInstanceIdChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value.toLowerCase();
    setFormData(prev => ({ ...prev, instance_id: value }));
    setIsInstanceIdManuallyEdited(true);
  };

  // Get create command for selected CMS
  const getCreateCommand = () => {
    if (!formData.cms_type) return '';
    
    const defaults = getCMSDefaults(formData.cms_type);
    const prefix = formData.database_prefix || defaults.prefix;
    
    // WordPress: <instance_id> <instance_name> <db_name> <domain> <cms_type> <db_user> <db_pass> <db_host> <db_prefix>
    // Joomla: <instance_id> <instance_name> <domain> <db_name> <db_user> <db_pass> <db_prefix>
    if (formData.cms_type === 'wordpress') {
      return `./${defaults.script} ${formData.instance_id} "${formData.instance_name}" ${formData.database_name} ${formData.domain} ${formData.cms_type} ${formData.database_user} ${formData.database_password} ${formData.database_host} ${prefix}`;
    } else if (formData.cms_type === 'joomla') {
      return `./${defaults.script} ${formData.instance_id} "${formData.instance_name}" ${formData.domain} ${formData.database_name} ${formData.database_user} ${formData.database_password} ${prefix}`;
    } else {
      return `./${defaults.script} ${formData.instance_id} "${formData.instance_name}" ${formData.domain} ${formData.database_name} ${formData.database_user} ${formData.database_password} ${prefix}`;
    }
  };

  const handleCMSTypeChange = (newCmsType: string) => {
    const defaults = getCMSDefaults(newCmsType);
    setFormData({
      ...formData,
      cms_type: newCmsType,
      database_prefix: defaults.prefix
    });
    // Reset manual edit flag when CMS type changes to regenerate ID with new prefix
    if (!isInstanceIdManuallyEdited) {
      setIsInstanceIdManuallyEdited(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);

    try {
      const response = await fetch('/api/instances/create', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(formData)
      });

      const data = await response.json();

      if (data.success) {
        // Show success message with installation URL
        const message = (
          <div>
            <p className="font-semibold">Instance created successfully!</p>
            {data.installation_url && (
              <div className="mt-2 space-y-1">
                <p className="text-sm">Installation URL:</p>
                <a 
                  href={data.installation_url} 
                  target="_blank" 
                  rel="noopener noreferrer"
                  className="text-sm text-blue-600 hover:underline block"
                >
                  {data.installation_url}
                </a>
              </div>
            )}
          </div>
        );
        toast.success(message, { duration: 10000 });
        
        // Navigate after a delay to allow user to see the URL
        setTimeout(() => navigate('/'), 3000);
      } else {
        toast.error(data.error || data.message || 'Failed to create instance');
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
              placeholder="ACME Corporation Website"
            />
            <p className="mt-1 text-sm text-gray-500">
              A friendly name for your instance
            </p>
          </div>

          {/* Instance ID */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Instance ID *
              {formData.instance_id && (
                <span className="ml-2">
                  {isInstanceIdValid(formData.instance_id) ? (
                    <span className="inline-flex items-center text-green-600 text-xs">
                      <CheckCircle className="w-3 h-3 mr-1" />
                      Valid
                    </span>
                  ) : (
                    <span className="inline-flex items-center text-red-600 text-xs">
                      <XCircle className="w-3 h-3 mr-1" />
                      Invalid format
                    </span>
                  )}
                </span>
              )}
            </label>
            <div className="relative">
              <input
                type="text"
                value={formData.instance_id}
                onChange={handleInstanceIdChange}
                onFocus={() => setIsInstanceIdManuallyEdited(true)}
                required
                className={`block w-full px-4 py-2.5 rounded border ${
                  formData.instance_id && !isInstanceIdValid(formData.instance_id)
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-primary-500 focus:ring-primary-500'
                } focus:ring-1 transition-colors`}
                placeholder="wp-acme-corporation"
              />
              {!isInstanceIdManuallyEdited && formData.instance_name && formData.cms_type && (
                <div className="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                  <span className="text-gray-400 text-xs bg-white px-1">
                    Auto-generated
                  </span>
                </div>
              )}
            </div>
            <p className="mt-1 text-sm text-gray-500">
              Used in URLs and directories. Format: lowercase, numbers, hyphens (3-50 chars)
            </p>
            {!isInstanceIdValid(formData.instance_id) && formData.instance_id && (
              <p className="mt-1 text-xs text-red-600">
                Must start and end with alphanumeric, contain only lowercase letters, numbers, and hyphens
              </p>
            )}
          </div>

          {/* CMS Type */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              CMS Type *
            </label>
            <select
              value={formData.cms_type}
              onChange={(e) => handleCMSTypeChange(e.target.value)}
              required
              className="block w-full px-4 py-2.5 rounded border border-gray-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
            >
              <option value="">Select CMS</option>
              <option value="wordpress">WordPress</option>
              <option value="joomla">Joomla</option>
              <option value="drupal">Drupal</option>
            </select>
            {formData.cms_type && (
              <div className="mt-2 p-3 bg-blue-50 border border-blue-200 rounded">
                <p className="text-xs text-blue-800 font-medium mb-1">
                  {formData.cms_type === 'wordpress' && '📦 WordPress - Shared core from shared-cores/wordpress/'}
                  {formData.cms_type === 'joomla' && '📦 Joomla - Shared core from shared-cores/joomla/'}
                  {formData.cms_type === 'drupal' && '📦 Drupal - Shared core from shared-cores/drupal/'}
                </p>
                <p className="text-xs text-blue-700">
                  Script: <code className="bg-blue-100 px-1 py-0.5 rounded">{getCMSDefaults(formData.cms_type).script}</code>
                </p>
              </div>
            )}
          </div>

          {/* Show remaining fields only when CMS is selected */}
          {formData.cms_type && (
            <>
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
                Main domain for your {formData.cms_type === 'wordpress' ? 'WordPress' : formData.cms_type === 'joomla' ? 'Joomla' : 'Drupal'} site (cached through kernel)
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
                {formData.cms_type === 'wordpress' && 'Subdomain for admin access (direct to WordPress)'}
                {formData.cms_type === 'joomla' && 'Subdomain for admin access (direct to Joomla administrator)'}
                {formData.cms_type === 'drupal' && 'Subdomain for admin access (direct to Drupal admin)'}
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
                placeholder={
                  formData.cms_type === 'wordpress' ? 'wp_' :
                  formData.cms_type === 'joomla' ? 'jos_' :
                  formData.cms_type === 'drupal' ? 'drupal_' : 'wp_'
                }
              />
              <p className="mt-1 text-xs text-gray-500">
                {formData.cms_type === 'wordpress' && 'Default: wp_ (WordPress tables)'}
                {formData.cms_type === 'joomla' && 'Default: jos_ (Joomla tables)'}
                {formData.cms_type === 'drupal' && 'Default: drupal_ (Drupal tables)'}
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

          {/* Create Command Preview */}
          {formData.cms_type && formData.instance_id && formData.domain && (
            <div className="border-t pt-6">
              <h3 className="text-sm font-medium text-gray-900 mb-2">CLI Command Preview</h3>
              <div className="bg-gray-900 text-green-400 p-4 rounded font-mono text-sm overflow-x-auto">
                <code>{getCreateCommand()}</code>
              </div>
              <p className="mt-2 text-xs text-gray-500">
                This command will be executed to create your {formData.cms_type} instance
              </p>
            </div>
          )}

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
              disabled={loading || !formData.cms_type || !isInstanceIdValid(formData.instance_id)}
              className="flex-1 px-6 py-3 border border-transparent rounded text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {loading ? 'Creating Instance...' : !formData.cms_type ? 'Select CMS to Continue' : !isInstanceIdValid(formData.instance_id) ? 'Fix Instance ID' : 'Create Instance'}
            </button>
          </div>
            </>
          )}
        </form>
      </div>
    </div>
  );
}
