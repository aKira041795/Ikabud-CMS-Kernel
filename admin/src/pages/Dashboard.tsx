import { useQuery } from '@tanstack/react-query'
import { Activity, Server, Cpu, HardDrive } from 'lucide-react'
import { api } from '../lib/api'

export default function Dashboard() {
  const { data: kernelStatus } = useQuery({
    queryKey: ['kernel-status'],
    queryFn: () => api.get('/kernel/status'),
    refetchInterval: 5000,
  })
  
  const { data: processes } = useQuery({
    queryKey: ['processes'],
    queryFn: () => api.get('/kernel/processes'),
    refetchInterval: 5000,
  })
  
  const stats = [
    {
      name: 'Kernel Version',
      value: kernelStatus?.version || '1.0.0',
      icon: Activity,
      color: 'text-blue-600',
    },
    {
      name: 'Running Processes',
      value: processes?.total || 0,
      icon: Server,
      color: 'text-green-600',
    },
    {
      name: 'Memory Usage',
      value: kernelStatus?.memory_usage 
        ? `${(kernelStatus.memory_usage / 1024 / 1024).toFixed(2)} MB`
        : '0 MB',
      icon: Cpu,
      color: 'text-purple-600',
    },
    {
      name: 'Syscalls Registered',
      value: kernelStatus?.syscalls_registered || 0,
      icon: HardDrive,
      color: 'text-orange-600',
    },
  ]
  
  return (
    <div>
      <h1 className="text-3xl font-bold text-gray-900 mb-8">Kernel Dashboard</h1>
      
      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        {stats.map((stat) => {
          const Icon = stat.icon
          return (
            <div key={stat.name} className="card">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-gray-600">{stat.name}</p>
                  <p className="text-2xl font-bold text-gray-900 mt-1">{stat.value}</p>
                </div>
                <Icon className={`w-8 h-8 ${stat.color}`} />
              </div>
            </div>
          )
        })}
      </div>
      
      {/* Kernel Status */}
      <div className="card mb-8">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Kernel Status</h2>
        <div className="space-y-2">
          <div className="flex justify-between">
            <span className="text-gray-600">Boot Status:</span>
            <span className={`font-medium ${kernelStatus?.booted ? 'text-green-600' : 'text-red-600'}`}>
              {kernelStatus?.booted ? 'Booted' : 'Not Booted'}
            </span>
          </div>
          <div className="flex justify-between">
            <span className="text-gray-600">Uptime:</span>
            <span className="font-medium">{kernelStatus?.uptime?.toFixed(2) || 0}s</span>
          </div>
          <div className="flex justify-between">
            <span className="text-gray-600">Peak Memory:</span>
            <span className="font-medium">
              {kernelStatus?.memory_peak 
                ? `${(kernelStatus.memory_peak / 1024 / 1024).toFixed(2)} MB`
                : '0 MB'}
            </span>
          </div>
        </div>
      </div>
      
      {/* Recent Processes */}
      <div className="card">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Recent Processes</h2>
        {processes?.processes && processes.processes.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-gray-200">
                  <th className="text-left py-2 px-4 text-sm font-medium text-gray-600">PID</th>
                  <th className="text-left py-2 px-4 text-sm font-medium text-gray-600">Name</th>
                  <th className="text-left py-2 px-4 text-sm font-medium text-gray-600">Type</th>
                  <th className="text-left py-2 px-4 text-sm font-medium text-gray-600">Status</th>
                  <th className="text-left py-2 px-4 text-sm font-medium text-gray-600">Boot Time</th>
                </tr>
              </thead>
              <tbody>
                {processes.processes.slice(0, 5).map((process: any) => (
                  <tr key={process.pid} className="border-b border-gray-100">
                    <td className="py-2 px-4 text-sm">{process.pid}</td>
                    <td className="py-2 px-4 text-sm">{process.process_name}</td>
                    <td className="py-2 px-4 text-sm">{process.cms_type}</td>
                    <td className="py-2 px-4 text-sm">
                      <span className={`px-2 py-1 rounded text-xs ${
                        process.status === 'running' ? 'bg-green-100 text-green-800' :
                        process.status === 'booting' ? 'bg-yellow-100 text-yellow-800' :
                        'bg-gray-100 text-gray-800'
                      }`}>
                        {process.status}
                      </span>
                    </td>
                    <td className="py-2 px-4 text-sm">{process.boot_time?.toFixed(2) || 0}ms</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-gray-500">No processes running</p>
        )}
      </div>
    </div>
  )
}
