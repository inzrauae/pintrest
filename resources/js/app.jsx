import '../css/app.css';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './lib/auth';
import { Loading, ToastProvider } from './components/ui';
import Layout from './components/Layout';
import { Login, Register } from './pages/Auth';
import Dashboard from './pages/Dashboard';
import CreatePin from './pages/CreatePin';
import PinEditor from './pages/PinEditor';
import BulkImport from './pages/BulkImport';
import BatchDetail from './pages/BatchDetail';
import SiteImport from './pages/SiteImport';
import SeoDashboard from './pages/SeoDashboard';
import SeoTopics from './pages/SeoTopics';
import PinLibrary from './pages/PinLibrary';
import PublishingQueue from './pages/PublishingQueue';
import Accounts from './pages/Accounts';
import Boards from './pages/Boards';
import AiSettings from './pages/AiSettings';
import Templates from './pages/Templates';
import Reports from './pages/Reports';
import UserSettings from './pages/UserSettings';

function Protected({ children }) {
    const { user } = useAuth();
    if (user === undefined) return <Loading />;
    return user ? children : <Navigate to="/login" replace />;
}

function GuestOnly({ children }) {
    const { user } = useAuth();
    if (user === undefined) return <Loading />;
    return user ? <Navigate to="/" replace /> : children;
}

function App() {
    return (
        <Routes>
            <Route path="/login" element={<GuestOnly><Login /></GuestOnly>} />
            <Route path="/register" element={<GuestOnly><Register /></GuestOnly>} />
            <Route element={<Protected><Layout /></Protected>}>
                <Route index element={<Dashboard />} />
                <Route path="create" element={<CreatePin />} />
                <Route path="pins" element={<PinLibrary />} />
                <Route path="pins/:id" element={<PinEditor />} />
                <Route path="bulk" element={<BulkImport />} />
                <Route path="bulk/:id" element={<BatchDetail />} />
                <Route path="site" element={<SiteImport />} />
                <Route path="seo" element={<SeoDashboard />} />
                <Route path="seo/topics" element={<SeoTopics />} />
                <Route path="queue" element={<PublishingQueue />} />
                <Route path="accounts" element={<Accounts />} />
                <Route path="boards" element={<Boards />} />
                <Route path="ai-settings" element={<AiSettings />} />
                <Route path="templates" element={<Templates />} />
                <Route path="reports" element={<Reports />} />
                <Route path="settings" element={<UserSettings />} />
                <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
        </Routes>
    );
}

createRoot(document.getElementById('app')).render(
    <BrowserRouter>
        <AuthProvider>
            <ToastProvider>
                <App />
            </ToastProvider>
        </AuthProvider>
    </BrowserRouter>,
);
