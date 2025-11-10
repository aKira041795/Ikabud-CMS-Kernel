import { useQuery } from '@tanstack/react-query';
import { useAuth } from '../contexts/AuthContext';
import { Activity, Cpu, Database, Clock, XCircle, Loader2, Server } from 'lucide-react';

export default function ProcessMonitor() {
  const { token } = useAuth();

  const { data: processes, isLoading, error } = useQuery({
    queryKey: ['kernel-processes'],
    queryFn: async () => {
      const response = await fetch('/api/kernel/processes', {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      
      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to fetch processes');
      }
      
      return response.json();
    },
    refetchInterval: 2000,
    retry: 1
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="w-8 h-8 text-primary-600 animate-spin" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-center">
          <XCircle className="mx-auto h-12 w-12 text-danger-500" />
          <h3 className="mt-2 text-sm font-medium text-gray-900">Error loading processes</h3>
          <p className="mt-1 text-sm text-gray-500">{(error as Error).message}</p>
        </div>
      </div>
    );
  }

  const stats = {
    total: processes?.total || 0,
    running: processes?.processes?.filter((p: any) => p.status === 'running').length || 0,
    stopped: processes?.processes?.filter((p: any) => p.status === 'stopped').length || 0,
    crashed: processes?.processes?.filter((p: any) => p.status === 'crashed').length || 0
  };

  const formatTime = (seconds: number) => {
    if (!seconds) return '0s';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
  };

  const formatBytes = (bytes: number) => {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-3xl font-bold text-gray-900">Process Monitor</h1>
        <p className="mt-2 text-gray-600">
          Real-time monitoring of kernel processes and CMS instances
        </p>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600">Total Processes</p>
              <p className="text-3xl font-bold text-gray-900 mt-1">{stats.total}</p>
            </div>
            <Activity className="w-12 h-12 text-primary-500" />
          </div>
        </div>

        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600">Running</p>
              <p className="text-3xl font-bold text-success-600 mt-1">{stats.running}</p>
            </div>
            <Cpu className="w-12 h-12 text-success-500" />
          </div>
        </div>

        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600">Stopped</p>
              <p className="text-3xl font-bold text-gray-600 mt-1">{stats.stopped}</p>
            </div>
            <Server className="w-12 h-12 text-gray-400" />
          </div>
        </div>

        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600">Crashed</p>
              <p className="text-3xl font-bold text-danger-600 mt-1">{stats.crashed}</p>
            </div>
            <XCircle className="w-12 h-12 text-danger-500" />
          </div>
        </div>
      </div>

      {/* Processes Table */}
      <div className="bg-white rounded-lg shadow-sm border border-gray-200">
        <div className="px-6 py-4 border-b border-gray-200">
          <h2 className="text-lg font-semibold text-gray-900">Active Processes</h2>
        </div>
        
        {processes?.processes?.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    PID
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Process Name
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Instance
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Type
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Status
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Memory
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    CPU Time
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Uptime
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {processes.processes.map((process: any) => (
                  <tr key={process.pid} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900">
                      {process.pid}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center">
                        <Database className="w-4 h-4 text-gray-400 mr-2" />
                        <div className="text-sm font-medium text-gray-900">
                          {process.process_name}
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {process.instance_id}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">
                        {process.process_type}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`px-3 py-1.5 text-xs font-semibold rounded-md uppercase ${
                        process.status === 'running' 
                          ? 'bg-green-600 text-white'
                          : process.status === 'crashed'
                          ? 'bg-red-600 text-white'
                          : 'bg-gray-600 text-white'
                      }`}>
                        {process.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {formatBytes(process.memory_usage || 0)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {formatTime(process.cpu_time || 0)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <div className="flex items-center">
                        <Clock className="w-4 h-4 text-gray-400 mr-1" />
                        {process.started_at ? new Date(process.started_at).toLocaleString() : 'N/A'}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="text-center py-12">
            <Activity className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-2 text-sm font-medium text-gray-900">No processes running</h3>
            <p className="mt-1 text-sm text-gray-500">Start an instance to see processes here.</p>
          </div>
        )}
      </div>
    </div>
  );
}
