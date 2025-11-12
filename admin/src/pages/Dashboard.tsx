import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { Server, Activity, XCircle, Database, Plus, Trash2, HardDrive } from 'lucide-react';
import { toast } from 'react-hot-toast';

export default function Dashboard() {
  const { token } = useAuth();
  const queryClient = useQueryClient();

  const { data: instances, isLoading, error } = useQuery({
    queryKey: ['instances'],
    queryFn: async () => {
      const response = await fetch('/api/v1/instances', {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      
      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to fetch instances');
      }
      
      const data = await response.json();
      return data;
    },
    refetchInterval: 5000,
    retry: 1
  });

  // Fetch cache stats
  const { data: cacheStats } = useQuery({
    queryKey: ['cache-stats'],
    queryFn: async () => {
      const response = await fetch('/api/v1/cache/stats', {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (!response.ok) throw new Error('Failed to fetch cache stats');
      return response.json();
    },
    refetchInterval: 10000
  });

  // Clear all cache mutation
  const clearAllCacheMutation = useMutation({
    mutationFn: async () => {
      const response = await fetch('/api/v1/cache', {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (!response.ok) throw new Error('Failed to clear cache');
      return response.json();
    },
    onSuccess: () => {
      toast.success('All cache cleared successfully!');
      queryClient.invalidateQueries({ queryKey: ['cache-stats'] });
    },
    onError: (error: Error) => {
      toast.error(`Failed to clear cache: ${error.message}`);
    }
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64">
      <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
    </div>;
  }

  if (error) {
    return <div className="flex items-center justify-center h-64">
      <div className="text-center">
        <XCircle className="mx-auto h-12 w-12 text-red-400" />
        <h3 className="mt-2 text-sm font-medium text-gray-900">Error loading instances</h3>
        <p className="mt-1 text-sm text-gray-500">{(error as Error).message}</p>
      </div>
    </div>;
  }

  const stats = {
    total: instances?.total || 0,
    running: instances?.instances?.filter((i: any) => i.status === 'active').length || 0,
    stopped: instances?.instances?.filter((i: any) => i.status === 'inactive').length || 0
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
      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-6">
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

        {/* Cache Stats Card */}
        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="p-5">
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center">
                <div className="flex-shrink-0">
                  <HardDrive className="h-6 w-6 text-blue-400" />
                </div>
                <div className="ml-3">
                  <dt className="text-sm font-medium text-gray-500">
                    Cache
                  </dt>
                  <dd className="text-lg font-semibold text-gray-900">
                    {cacheStats?.hit_rate || '0%'}
                  </dd>
                </div>
              </div>
            </div>
            <div className="text-xs text-gray-500 space-y-1 mb-3">
              <div>Hits: {cacheStats?.hits || 0}</div>
              <div>Misses: {cacheStats?.misses || 0}</div>
            </div>
            <button
              onClick={() => {
                if (confirm('Clear all cache? This will affect all instances.')) {
                  clearAllCacheMutation.mutate();
                }
              }}
              disabled={clearAllCacheMutation.isPending}
              className="w-full inline-flex items-center justify-center px-3 py-1.5 border border-red-300 text-xs font-medium rounded text-red-700 bg-white hover:bg-red-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <Trash2 className="w-3 h-3 mr-1" />
              {clearAllCacheMutation.isPending ? 'Clearing...' : 'Clear All Cache'}
            </button>
          </div>
        </div>
      </div>

      {/* Instances Grid */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {instances?.instances?.length > 0 ? instances.instances.map((instance: any) => (
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
        )) : (
          <div className="col-span-full text-center py-12">
            <Server className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-2 text-sm font-medium text-gray-900">No instances</h3>
            <p className="mt-1 text-sm text-gray-500">Get started by creating a new instance.</p>
            <div className="mt-6">
              <Link
                to="/instances/create"
                className="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700"
              >
                <Plus className="w-4 h-4 mr-2" />
                Create Instance
              </Link>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
