import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { prefetchSiteData, WEBSITE_PATHS } from './website/siteData'
import { preloadWebsitePage } from './website/pageLoaders'
import { AUTH_TOKEN_KEY } from './core/api/axiosInstance'

// Website: ask for the content and the page's code right away, in parallel with React starting,
// instead of one after the other — the page shows without a loader.
// ("/" is the dashboard for a signed-in user, so only a guest gets the website there.)
const path = window.location.pathname
let signedIn = false
try {
  signedIn = Boolean(localStorage.getItem(AUTH_TOKEN_KEY))
} catch {
  // storage blocked: treat as a guest
}
if (WEBSITE_PATHS.includes(path) && !(path === '/' && signedIn)) {
  prefetchSiteData()
  preloadWebsitePage(path)
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
