import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import templateService from '../../services/templateService';
import ApiKeyModal from './ApiKeyModal';

/**
 * Warning card shown on the pages that need the Developer API key (Send Notification, Message Logs, WhatsApp setup)
 * until the account has both an API key and a registered server IP. Hidden when the plan has no API access.
 */
export default function ApiKeySetupBanner() {
  const { user } = useAuth();
  const apiInPlan = user?.account?.api_access === true;
  const subscriptionActive = user?.account?.subscription_active !== false;

  const [setupIncomplete, setSetupIncomplete] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);

  const refresh = useCallback(() => {
    if (!apiInPlan) return;
    templateService
      .getApiKey()
      .then((res) => setSetupIncomplete(res.data === null || res.server_ip.authorized_server_ip === null))
      .catch(() => setSetupIncomplete(false));
  }, [apiInPlan]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  // The card hides as soon as the setup is complete; the modal stays open until the user closes it.
  const showCard = apiInPlan && setupIncomplete;

  return (
    <>
      {showCard && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
          <p className="flex items-start gap-2 text-sm text-amber-900">
            <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-600" />
            <span>
              <span className="font-semibold">Action Required:</span> API Key &amp; Server IP not registered. Please generate your API key to
              enable API services.
            </span>
          </p>
          <button
            type="button"
            onClick={() => setModalOpen(true)}
            className="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700"
          >
            Set up API key
          </button>
        </div>
      )}

      {modalOpen && (
        <ApiKeyModal
          subscriptionActive={subscriptionActive}
          onClose={() => {
            setModalOpen(false);
            refresh();
          }}
        />
      )}
    </>
  );
}
