import { Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider } from './contexts/AuthContext'
import Layout from './components/Layout'
import ProtectedRoute from './components/ProtectedRoute'
import Login from './pages/Login'
import Dashboard from './pages/Dashboard'
import Instances from './pages/Instances'
import Themes from './pages/Themes'
import DSLBuilder from './pages/DSLBuilder'
import ProcessMonitor from './pages/ProcessMonitor'
import Settings from './pages/Settings'
import CreateInstance from './pages/CreateInstance'
import InstanceMonitor from './pages/InstanceMonitor'

function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/" element={
          <ProtectedRoute>
            <Layout>
              <Dashboard />
            </Layout>
          </ProtectedRoute>
        } />
        <Route path="/instances" element={
          <ProtectedRoute>
            <Layout>
              <Instances />
            </Layout>
          </ProtectedRoute>
        } />
        <Route path="/instances/create" element={
          <ProtectedRoute>
            <Layout>
              <CreateInstance />
            </Layout>
          </ProtectedRoute>
        } />
        <Route path="/instances/:instanceId" element={
          <ProtectedRoute>
            <Layout>
              <InstanceMonitor />
            </Layout>
          </ProtectedRoute>
        } />
        <Route path="/themes" element={
          <ProtectedRoute>
            <Layout>
              <Themes />
            </Layout>
          </ProtectedRoute>
        } />
        <Route path="/dsl" element={
          <ProtectedRoute>
            <Layout>
              <DSLBuilder />
            </Layout>
          </ProtectedRoute>
        } />
        <Route path="/processes" element={
          <ProtectedRoute>
            <Layout>
              <ProcessMonitor />
            </Layout>
          </ProtectedRoute>
        } />
        <Route path="/settings" element={
          <ProtectedRoute>
            <Layout>
              <Settings />
            </Layout>
          </ProtectedRoute>
        } />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </AuthProvider>
  )
}

export default App
