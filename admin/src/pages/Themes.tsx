import { Link } from 'react-router-dom'
import { 
  Palette, 
  Sparkles, 
  Layout, 
  Code2, 
  FolderOpen,
  Plus,
  ArrowRight,
  Layers,
  Paintbrush,
  FileCode
} from 'lucide-react'

export default function Themes() {
  const features = [
    {
      icon: Sparkles,
      title: 'Visual Builder',
      description: 'Drag-and-drop interface for building DiSyL templates without writing code',
      link: '/themes/visual-builder',
      color: 'blue',
      badge: 'New'
    },
    {
      icon: Code2,
      title: 'Code Editor',
      description: 'Advanced DiSyL code editor with syntax highlighting and validation',
      link: '/themes/editor',
      color: 'purple',
      badge: null
    },
    {
      icon: FolderOpen,
      title: 'Theme Manager',
      description: 'Browse, install, and manage themes across your CMS instances',
      link: '/themes/manager',
      color: 'green',
      badge: null
    }
  ]

  const quickActions = [
    { icon: Plus, label: 'New Theme', description: 'Start from scratch' },
    { icon: Layout, label: 'Templates', description: 'Pre-built layouts' },
    { icon: Paintbrush, label: 'Customize', description: 'Edit existing theme' }
  ]

  return (
    <div>
      {/* Header */}
      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-3">
            <Palette className="w-8 h-8 text-blue-500" />
            Theme Builder
          </h1>
          <p className="text-gray-600 mt-2">
            Create and manage DiSyL themes with our intuitive visual tools
          </p>
        </div>
      </div>

      {/* Feature Cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        {features.map((feature) => (
          <Link
            key={feature.title}
            to={feature.link}
            className="group relative bg-white rounded-xl border border-gray-200 p-6 hover:border-blue-300 hover:shadow-lg transition-all duration-200"
          >
            {feature.badge && (
              <span className="absolute top-4 right-4 px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 rounded-full">
                {feature.badge}
              </span>
            )}
            <div className={`inline-flex p-3 rounded-xl bg-${feature.color}-50 mb-4`}>
              <feature.icon className={`w-6 h-6 text-${feature.color}-500`} />
            </div>
            <h3 className="text-lg font-semibold text-gray-900 mb-2 group-hover:text-blue-600 transition-colors">
              {feature.title}
            </h3>
            <p className="text-gray-600 text-sm mb-4">
              {feature.description}
            </p>
            <div className="flex items-center text-sm font-medium text-blue-600 group-hover:gap-2 transition-all">
              Open <ArrowRight className="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" />
            </div>
          </Link>
        ))}
      </div>

      {/* Quick Actions */}
      <div className="bg-white rounded-xl border border-gray-200 p-6 mb-8">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {quickActions.map((action) => (
            <button
              key={action.label}
              className="flex items-center gap-4 p-4 rounded-lg border border-gray-200 hover:border-blue-300 hover:bg-blue-50 transition-all text-left"
            >
              <div className="p-2 bg-gray-100 rounded-lg">
                <action.icon className="w-5 h-5 text-gray-600" />
              </div>
              <div>
                <div className="font-medium text-gray-900">{action.label}</div>
                <div className="text-sm text-gray-500">{action.description}</div>
              </div>
            </button>
          ))}
        </div>
      </div>

      {/* DiSyL Info */}
      <div className="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-100 p-6">
        <div className="flex items-start gap-4">
          <div className="p-3 bg-white rounded-xl shadow-sm">
            <FileCode className="w-6 h-6 text-blue-500" />
          </div>
          <div className="flex-1">
            <h3 className="text-lg font-semibold text-gray-900 mb-2">
              What is DiSyL?
            </h3>
            <p className="text-gray-600 text-sm mb-4">
              DiSyL (Display Syntax Language) is a powerful templating language designed for creating 
              cross-platform themes. It supports WordPress, Joomla, Drupal, and native rendering with 
              a unified syntax.
            </p>
            <div className="flex flex-wrap gap-2">
              <span className="px-3 py-1 bg-white text-sm text-gray-700 rounded-full border border-gray-200">
                <Layers className="w-3 h-3 inline mr-1" /> Components
              </span>
              <span className="px-3 py-1 bg-white text-sm text-gray-700 rounded-full border border-gray-200">
                Filters
              </span>
              <span className="px-3 py-1 bg-white text-sm text-gray-700 rounded-full border border-gray-200">
                Control Structures
              </span>
              <span className="px-3 py-1 bg-white text-sm text-gray-700 rounded-full border border-gray-200">
                Expressions
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
