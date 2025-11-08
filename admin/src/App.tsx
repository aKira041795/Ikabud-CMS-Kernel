import { Routes, Route } from 'react-router-dom'
import Layout from './components/Layout'
import Dashboard from './pages/Dashboard'
import Instances from './pages/Instances'
import Themes from './pages/Themes'
import DSLBuilder from './pages/DSLBuilder'
import ProcessMonitor from './pages/ProcessMonitor'
import Settings from './pages/Settings'

function App() {
  return (
    <Layout>
      <Routes>
        <Route path="/" element={<Dashboard />} />
        <Route path="/instances" element={<Instances />} />
        <Route path="/themes" element={<Themes />} />
        <Route path="/dsl" element={<DSLBuilder />} />
        <Route path="/processes" element={<ProcessMonitor />} />
        <Route path="/settings" element={<Settings />} />
      </Routes>
    </Layout>
  )
}

export default App
