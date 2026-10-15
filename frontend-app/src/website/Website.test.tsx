import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import WebsiteLayout from './WebsiteLayout';
import HomePage from './pages/HomePage';
import PricingPage from './pages/PricingPage';
import ContactPage from './pages/ContactPage';
import SignupPage from './pages/SignupPage';
import { resetSiteDataForTests } from './siteData';
import LegalPage from './pages/LegalPage';
import { SiteContentProvider } from './SiteContentContext';
import { ProtectedRoute } from '../core/guards/ProtectedRoute';
import publicSiteService from '../services/publicSiteService';
import type { SiteContent } from '../types/site';

vi.mock('../services/publicSiteService', () => ({
  default: { site: vi.fn(), plans: vi.fn(), contact: vi.fn(), register: vi.fn(), sendSignupCode: vi.fn() },
}));

const auth = { authenticated: false, refreshUser: vi.fn(() => Promise.resolve()) };
vi.mock('../core/context/AuthContext', () => ({
  useAuth: () => ({
    isLoading: false,
    isAuthenticated: auth.authenticated,
    user: auth.authenticated ? { id: 1, roles: [], permissions: [] } : null,
    hasPermission: () => true,
    hasRole: () => false,
    isSuperAdmin: () => false,
    hasModule: () => true,
    isReadOnly: () => false,
    refreshUser: auth.refreshUser,
  }),
}));
vi.mock('../core/hooks/useEffectiveHasModule', () => ({ useEffectiveHasModule: () => () => true }));

const site: SiteContent = {
  brand: { name: 'TestBrand', description: 'All in one', signup_enabled: true, trial_days: 7, trial_messages: 50, support_email: 'hello@testbrand.test', social_links: [{ label: 'Bad', url: 'javascript:alert(1)' }] },
  home: {
    hero: { eyebrow: 'Eyebrow', title: 'Grow on WhatsApp', subtitle: 'Sub', primary_cta: 'Get started', secondary_cta: 'See features' },
    highlights: [{ value: 'QR + API', label: 'Connect' }],
    steps_title: 'Live in minutes',
    steps: [{ title: 'Sign up', description: 'Create account' }],
    cta: { title: 'Ready?', subtitle: 'Go', button: 'Create free account' },
  },
  features: { title: 'Features', modules: [{ icon: 'message', title: 'WhatsApp messaging', description: 'Reach customers', bullets: ['QR'] }] },
  about: { title: 'About us' },
  pricing: { title: 'Pricing', subtitle: 'Plans', notes: ['One number included'], cta: 'Choose plan' },
  contact: { title: 'Contact us', topics: ['Sales'], success: 'Thanks, got it!' },
  faq: { title: 'FAQ', items: [{ question: 'Need the API?', answer: 'No, QR works.' }] },
  legal: { privacy: { title: 'Privacy Policy', updated: '2026-10-12', sections: [{ heading: 'Who we are', body: 'Us.' }] } },
};

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route element={<WebsiteLayout />}>
          <Route path="/home" element={<HomePage />} />
          <Route path="/pricing" element={<PricingPage />} />
          <Route path="/contact" element={<ContactPage />} />
          <Route path="/privacy" element={<LegalPage page="privacy" />} />
        </Route>
        <Route path="/" element={<ProtectedRoute><div>dashboard</div></ProtectedRoute>} />
        <Route path="/signup" element={<SiteContentProvider><SignupPage /></SiteContentProvider>} />
        <Route path="/billing" element={<div>billing page</div>} />
        <Route path="/login" element={<div>login page</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('Public website', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    auth.authenticated = false;
    localStorage.clear();
    // The site data is fetched once per page load and remembered; each test is its own page load.
    resetSiteDataForTests();
    (publicSiteService.site as Mock).mockResolvedValue(site);
  });

  it('sends a guest who opens the site root to the website home', async () => {
    renderAt('/');
    expect(await screen.findByRole('heading', { name: 'Grow on WhatsApp' })).toBeTruthy();
    expect(screen.queryByText('dashboard')).toBeNull();
  });

  it('renders the home page from the content API, with sign-up links and no unsafe social links', async () => {
    renderAt('/home');
    expect(await screen.findByRole('heading', { name: 'Grow on WhatsApp' })).toBeTruthy();
    expect(screen.getAllByText('TestBrand').length).toBeGreaterThan(0);
    expect(screen.getByText('Need the API?')).toBeTruthy();
    expect(screen.getAllByRole('link', { name: /Get started/ })[0].getAttribute('href')).toBe('/signup');
    expect(screen.queryByRole('link', { name: 'Bad' })).toBeNull();
  });

  it('shows "Go to dashboard" instead of sign-up to a logged-in user', async () => {
    auth.authenticated = true;
    renderAt('/home');
    await screen.findByRole('heading', { name: 'Grow on WhatsApp' });
    expect(screen.getAllByRole('link', { name: /Go to dashboard/ }).length).toBeGreaterThan(0);
    expect(screen.queryByRole('link', { name: 'Log in' })).toBeNull();
  });

  it('lists plans from the API with their features and links to sign-up with the plan', async () => {
    (publicSiteService.plans as Mock).mockResolvedValue({
      tax_rate: 0.18,
      data: [{ key: 'starter', label: 'Starter', description: 'For small teams', engine_type: 'qr', billing_model: 'flat_quota', total_allocated_messages: 1000, duration_days: 30, price: '999.00', tax_amount: '179.82', total_amount: '1178.82', features: ['CRM'] }],
    });
    renderAt('/pricing');

    const card = await screen.findByTestId('plan-starter');
    expect(within(card).getByText('₹1,178.82')).toBeTruthy();
    expect(within(card).getByText('incl. 18% GST (₹999 + ₹179.82 GST)')).toBeTruthy();
    expect(within(card).getByText('/ month')).toBeTruthy();
    expect(within(card).getAllByText('1,000 messages').length).toBeGreaterThan(0);
    expect(within(card).getByText('CRM')).toBeTruthy();
    expect(within(card).getByRole('link', { name: 'Choose plan' }).getAttribute('href')).toBe('/signup?plan=starter');
  });

  it('shows plan limits, a CRM-only plan without WhatsApp lines, and the Custom card', async () => {
    const base = { description: null, billing_model: 'flat_quota', duration_days: 30, tax_amount: '0.00', features: [] };
    (publicSiteService.plans as Mock).mockResolvedValue({
      tax_rate: 0,
      data: [
        { ...base, key: 'crm', label: 'CRM', engine_type: 'none', billing_model: 'unlimited', total_allocated_messages: null, price: '899.00', total_amount: '899.00',
          limits: { max_users: 3, max_meta_templates: 0, max_flows: 0, max_broadcasts_per_month: 0, marketing_message_limit: 0 } },
        { ...base, key: 'meta_pro', label: 'Meta Pro', engine_type: 'meta', total_allocated_messages: 10000, price: '7999.00', total_amount: '7999.00',
          limits: { max_users: 5, max_meta_templates: 30, max_flows: 10, max_broadcasts_per_month: 20, marketing_message_limit: 4000 } },
      ],
    });
    renderAt('/pricing');

    const crm = await screen.findByTestId('plan-crm');
    expect(within(crm).getByText('₹899')).toBeTruthy();
    expect(within(crm).queryByText(/GST/)).toBeNull();
    expect(within(crm).getByText('CRM only (no WhatsApp sending)')).toBeTruthy();
    expect(within(crm).queryByText('1 WhatsApp number included')).toBeNull();
    expect(within(crm).getByText('3 team users')).toBeTruthy();

    const pro = screen.getByTestId('plan-meta_pro');
    expect(within(pro).getByText('20 broadcasts / month')).toBeTruthy();
    expect(within(pro).getByText('4,000 marketing messages / month')).toBeTruthy();

    const custom = screen.getByTestId('plan-custom');
    expect(within(custom).getByRole('link', { name: 'Contact sales' }).getAttribute('href')).toBe('/contact');
  });

  it('uses the feature list set in Website Content and marks the featured plan', async () => {
    (publicSiteService.site as Mock).mockResolvedValue({
      ...site,
      pricing: { ...site.pricing, featured_plan: 'starter', plan_features: [{ plan: 'starter', features: ['Keyword chatbot rules'] }] },
    });
    const plan = { description: null, engine_type: 'qr', billing_model: 'flat_quota', duration_days: 30, tax_amount: '0', features: ['WhatsApp Messaging'] };
    (publicSiteService.plans as Mock).mockResolvedValue({
      tax_rate: 0,
      data: [
        { ...plan, key: 'starter', label: 'Starter', total_allocated_messages: 500, price: '599.00', total_amount: '599.00' },
        { ...plan, key: 'growth', label: 'Growth', total_allocated_messages: 2500, price: '1999.00', total_amount: '1999.00' },
      ],
    });
    renderAt('/pricing');

    const starter = await screen.findByTestId('plan-starter');
    expect(within(starter).getByText('₹599')).toBeTruthy();
    expect(within(starter).getByText('Keyword chatbot rules')).toBeTruthy();
    expect(within(starter).queryByText('WhatsApp Messaging')).toBeNull();
    expect(within(starter).getByText('Recommended')).toBeTruthy();
    expect(within(screen.getByTestId('plan-growth')).getByText('WhatsApp Messaging')).toBeTruthy();
  });

  it('sends the contact form and shows the success message', async () => {
    (publicSiteService.contact as Mock).mockResolvedValue({ message: 'ok' });
    const user = userEvent.setup();
    renderAt('/contact');

    await user.type(await screen.findByLabelText('Your name'), 'Ravi');
    await user.type(screen.getByLabelText('Email'), 'ravi@example.test');
    await user.click(screen.getByRole('radio', { name: 'Sales' }));
    await user.type(screen.getByLabelText('Message'), 'Need a demo');
    await user.click(screen.getByRole('button', { name: 'Send message' }));

    expect(publicSiteService.contact).toHaveBeenCalledWith(expect.objectContaining({ name: 'Ravi', email: 'ravi@example.test', topic: 'Sales', message: 'Need a demo' }));
    expect(await screen.findByText('Thanks, got it!')).toBeTruthy();
  });

  it('names the browser tab "<brand> | <page>"', async () => {
    renderAt('/contact');
    await screen.findByLabelText('Your name');
    await waitFor(() => expect(document.title).toBe('TestBrand | Contact'));
  });

  it('validates the contact form before sending', async () => {
    const user = userEvent.setup();
    renderAt('/contact');
    await user.click(await screen.findByRole('button', { name: 'Send message' }));
    expect(screen.getByText('Please enter your name.')).toBeTruthy();
    expect(publicSiteService.contact).not.toHaveBeenCalled();
  });

  async function fillSignupDetails(user: ReturnType<typeof userEvent.setup>) {
    await user.type(await screen.findByLabelText('Business name'), 'Acme');
    await user.type(screen.getByLabelText('Your name'), 'Asha');
    await user.type(screen.getByLabelText('Work email'), 'asha@acme.test');
    await user.type(screen.getByLabelText('Password'), 'secret-pass');
    await user.type(screen.getByLabelText('Confirm password'), 'secret-pass');
    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: 'Continue' }));
  }

  it('emails a code, then signs up with it, stores the token and opens Billing when a plan was picked', async () => {
    (publicSiteService.sendSignupCode as Mock).mockResolvedValue({ message: 'sent', expires_in_minutes: 10 });
    (publicSiteService.register as Mock).mockResolvedValue({ token: 'tok-123', token_type: 'Bearer', user: { id: 9 } });
    const user = userEvent.setup();
    renderAt('/signup?plan=starter');

    await fillSignupDetails(user);
    expect(publicSiteService.sendSignupCode).toHaveBeenCalledWith('asha@acme.test');
    expect(publicSiteService.register).not.toHaveBeenCalled();
    expect(await screen.findByText('Check your inbox')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Resend code in \d+s/ })).toBeTruthy();

    await user.type(screen.getByLabelText('Verification code'), '482913');
    await user.click(screen.getByRole('button', { name: 'Verify & create account' }));

    await waitFor(() => expect(screen.getByText('billing page')).toBeTruthy());
    expect(publicSiteService.register).toHaveBeenCalledWith(expect.objectContaining({ company_name: 'Acme', email: 'asha@acme.test', accept_terms: true, email_code: '482913' }));
    expect(localStorage.getItem('auth_token')).toBe('tok-123');
    expect(auth.refreshUser).toHaveBeenCalled();
  });

  it('shows why an email is refused before sending any code', async () => {
    (publicSiteService.sendSignupCode as Mock).mockRejectedValue({ response: { status: 422, data: { errors: { email: ['Temporary or disposable email addresses are not allowed.'] } } } });
    const user = userEvent.setup();
    renderAt('/signup');

    await fillSignupDetails(user);

    expect(await screen.findByText('Temporary or disposable email addresses are not allowed.')).toBeTruthy();
    expect(screen.queryByText('Check your inbox')).toBeNull();
    expect(localStorage.getItem('auth_token')).toBeNull();
  });

  it('keeps the visitor on the code step when the code is wrong', async () => {
    (publicSiteService.sendSignupCode as Mock).mockResolvedValue({ message: 'sent', expires_in_minutes: 10 });
    (publicSiteService.register as Mock).mockRejectedValue({ response: { status: 422, data: { errors: { email_code: ['The code is not correct.'] } } } });
    const user = userEvent.setup();
    renderAt('/signup');

    await fillSignupDetails(user);
    await user.type(await screen.findByLabelText('Verification code'), '000000');
    await user.click(screen.getByRole('button', { name: 'Verify & create account' }));

    expect(await screen.findByText('The code is not correct.')).toBeTruthy();
    expect(screen.getByLabelText('Verification code')).toBeTruthy();
  });

  it('shows a closed notice when the Super Admin switched sign-up off', async () => {
    (publicSiteService.site as Mock).mockResolvedValue({ ...site, brand: { ...site.brand, signup_enabled: false } });
    renderAt('/signup');
    expect(await screen.findByText('Sign-up is closed right now')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Continue' })).toBeNull();
  });

  it('renders a legal page from content', async () => {
    renderAt('/privacy');
    expect(await screen.findByRole('heading', { name: 'Privacy Policy' })).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Who we are' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Who we are' }).getAttribute('href')).toBe('#section-1');
    expect(screen.getByText('Last updated: 2026-10-12')).toBeTruthy();
  });
});
