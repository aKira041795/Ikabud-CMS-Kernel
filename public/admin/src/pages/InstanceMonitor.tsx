import { useQuery } from '@tanstack/react-query';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { ArrowLeft, Activity, Cpu, Database, HardDrive, RefreshCw } from 'lucide-react';

export default function InstanceMonitor() {
  const { instanceId } = useParams();
  const { token } = useAuth();
  const navigate = useNavigate();

  const { data: monitoring, isLoading, refetch } = useQuery({
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
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
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
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <button
          onClick={() => navigate('/')}
          className="inline-flex items-center text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="w-4 h-4 mr-1" />
          Back to Dashboard
        </button>
        <button
          onClick={() => refetch()}
          className="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
        >
          <RefreshCw className="w-4 h-4 mr-2" />
          Refresh
        </button>
      </div>

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
              <p className={`text-lg font-semibold capitalize ${
                monitoring?.instance.status === 'active' ? 'text-green-600' : 'text-red-600'
              }`}>
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

      {/* Instance Details */}
      <div className="bg-white shadow rounded-lg p-6 mb-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">
          Instance Details
        </h2>
        <dl className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2">
          <div>
            <dt className="text-sm font-medium text-gray-500">Domain</dt>
            <dd className="mt-1 text-sm text-gray-900">{monitoring?.instance.domain}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">CMS Type</dt>
            <dd className="mt-1 text-sm text-gray-900 capitalize">{monitoring?.instance.cms_type}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Database</dt>
            <dd className="mt-1 text-sm text-gray-900">{monitoring?.instance.database_name}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Created</dt>
            <dd className="mt-1 text-sm text-gray-900">{monitoring?.instance.created_at}</dd>
          </div>
        </dl>
      </div>

      {/* Process Info */}
      {monitoring?.process && (
        <div className="bg-white shadow rounded-lg p-6 mb-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            Process Information
          </h2>
          <dl className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2">
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
            <div className="sm:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Socket</dt>
              <dd className="mt-1 text-sm text-gray-900 font-mono text-xs break-all">
                {monitoring.process.socket}
              </dd>
            </div>
            {monitoring.health && (
              <div>
                <dt className="text-sm font-medium text-gray-500">Health</dt>
                <dd className="mt-1">
                  <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                    monitoring.health.healthy 
                      ? 'bg-green-100 text-green-800' 
                      : 'bg-red-100 text-red-800'
                  }`}>
                    {monitoring.health.healthy ? 'Healthy' : 'Unhealthy'}
                  </span>
                </dd>
              </div>
            )}
          </dl>
        </div>
      )}

      {/* CMS Info */}
      {monitoring?.cms && (
        <div className="bg-white shadow rounded-lg p-6 mb-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            CMS Information
          </h2>
          <dl className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2">
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
            <div>
              <dt className="text-sm font-medium text-gray-500">Memory Peak</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {formatBytes(monitoring.cms.resources?.memory_peak || 0)}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Initialized</dt>
              <dd className="mt-1">
                <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                  monitoring.cms.initialized 
                    ? 'bg-green-100 text-green-800' 
                    : 'bg-gray-100 text-gray-800'
                }`}>
                  {monitoring.cms.initialized ? 'Yes' : 'No'}
                </span>
              </dd>
            </div>
          </dl>
        </div>
      )}

      {/* Actions */}
      <div className="bg-white shadow rounded-lg p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Actions</h2>
        <div className="flex flex-wrap gap-3">
          <button className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">
            Start
          </button>
          <button className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-yellow-600 hover:bg-yellow-700">
            Restart
          </button>
          <button className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700">
            Stop
          </button>
          <button className="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50">
            View Logs
          </button>
        </div>
      </div>
    </div>
  );
}
