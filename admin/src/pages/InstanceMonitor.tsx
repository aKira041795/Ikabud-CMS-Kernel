import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { ArrowLeft, Activity, Cpu, Database, HardDrive, Play, Square, RotateCw, ExternalLink } from 'lucide-react';
import toast from 'react-hot-toast';

export default function InstanceMonitor() {
  const { instanceId } = useParams();
  const { token } = useAuth();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [actionLoading, setActionLoading] = useState<string | null>(null);

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

  const handleAction = async (action: string) => {
    setActionLoading(action);
    try {
      const response = await fetch(`/api/instances/${action}.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ instance_id: instanceId })
      });
      
      const data = await response.json();
      
      if (data.success) {
        toast.success(`Instance ${action}ed successfully`);
        queryClient.invalidateQueries({ queryKey: ['instance-monitor', instanceId] });
      } else {
        toast.error(data.message || `Failed to ${action} instance`);
      }
    } catch (error) {
      toast.error(`Error: ${(error as Error).message}`);
    } finally {
      setActionLoading(null);
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

      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {monitoring?.instance.instance_name}
          </h1>
          <p className="text-gray-500">{monitoring?.instance.instance_id}</p>
          {monitoring?.instance.domain && (
            <a 
              href={`http://${monitoring.instance.domain}`}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center text-sm text-blue-600 hover:text-blue-800 mt-1"
            >
              {monitoring.instance.domain}
              <ExternalLink className="w-3 h-3 ml-1" />
            </a>
          )}
        </div>
        
        <div className="flex gap-2">
          <button
            onClick={() => handleAction('start')}
            disabled={actionLoading !== null || monitoring?.instance.status === 'active'}
            className="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <Play className="w-4 h-4 mr-2" />
            {actionLoading === 'start' ? 'Starting...' : 'Start'}
          </button>
          
          <button
            onClick={() => handleAction('stop')}
            disabled={actionLoading !== null || monitoring?.instance.status !== 'active'}
            className="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <Square className="w-4 h-4 mr-2" />
            {actionLoading === 'stop' ? 'Stopping...' : 'Stop'}
          </button>
          
          <button
            onClick={() => handleAction('restart')}
            disabled={actionLoading !== null}
            className="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <RotateCw className="w-4 h-4 mr-2" />
            {actionLoading === 'restart' ? 'Restarting...' : 'Restart'}
          </button>
        </div>
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
              {monitoring?.process?.status === 'symlink_mode' && (
                <p className="text-xs text-gray-500 mt-1">Symlink Mode</p>
              )}
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
