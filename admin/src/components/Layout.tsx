import { Link, useLocation } from 'react-router-dom'
import { 
  LayoutDashboard, 
  Server, 
  Palette, 
  Code, 
  Activity, 
  Settings 
} from 'lucide-react'

interface LayoutProps {
  children: React.ReactNode
}

export default function Layout({ children }: LayoutProps) {
  const location = useLocation()
  
  const navigation = [
    { name: 'Dashboard', href: '/', icon: LayoutDashboard },
    { name: 'Instances', href: '/instances', icon: Server },
    { name: 'Themes', href: '/themes', icon: Palette },
    { name: 'DSL Builder', href: '/dsl', icon: Code },
    { name: 'Processes', href: '/processes', icon: Activity },
    { name: 'Settings', href: '/settings', icon: Settings },
  ]
  
  return (
    <div className="flex h-screen bg-gray-50">
      {/* Sidebar */}
      <aside className="w-64 bg-white border-r border-gray-200">
        <div className="flex flex-col h-full">
          {/* Logo */}
          <div className="flex items-center h-16 px-6 border-b border-gray-200">
            <h1 className="text-xl font-bold text-gray-900">Ikabud Kernel</h1>
          </div>
          
          {/* Navigation */}
          <nav className="flex-1 px-4 py-4 space-y-1">
            {navigation.map((item) => {
              const isActive = location.pathname === item.href
              const Icon = item.icon
              
              return (
                <Link
                  key={item.name}
                  to={item.href}
                  className={`flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors ${
                    isActive
                      ? 'bg-primary-50 text-primary-700'
                      : 'text-gray-700 hover:bg-gray-100'
                  }`}
                >
                  <Icon className="w-5 h-5 mr-3" />
                  {item.name}
                </Link>
              )
            })}
          </nav>
          
          {/* Footer */}
          <div className="p-4 border-t border-gray-200">
            <div className="text-xs text-gray-500">
              <div>Kernel v1.0.0</div>
              <div className="mt-1">Admin Interface</div>
            </div>
          </div>
        </div>
      </aside>
      
      {/* Main content */}
      <main className="flex-1 overflow-auto">
        <div className="p-8">
          {children}
        </div>
      </main>
    </div>
  )
}
