import { journeyNodesByCategory } from '../journey/nodeRegistry';
import { JOURNEY_NODE_CATEGORIES, JOURNEY_NODE_CATEGORY_LABELS, type JourneyNodeCategory } from '../types/journeyNodes';
import type { TourStep } from './tourContent';

/**
 * The Journey nodes tour (owner, 2026-10-15): how the builder works and what
 * every node does. Node names and descriptions come from the node registry —
 * the same list the palette shows — so the tour can never describe a node
 * that does not exist or miss a new one. Loaded only inside the Journey
 * Builder (it pulls in the registry), never on the dashboard.
 */

const CATEGORY_INTRO: Record<JourneyNodeCategory, string> = {
  message: 'Send something to the customer: text, image, video, document, audio, sticker — or let AI write the reply.',
  interactive: 'Ask the customer to choose or share something. Each button or list row gets its own branch, so the journey follows their answer.',
  advanced: 'Templates, conditions, API calls, payments, AI agents and hand-over to a person — the building blocks for smarter journeys.',
  utility: 'Helpers that shape the flow: wait (delay), run another journey, send an email, save a lead to the CRM, or end the journey.',
};

export function buildJourneyNodesTour(): TourStep[] {
  const steps: TourStep[] = [
    {
      title: 'Your journey canvas',
      body: 'A journey is an automatic WhatsApp conversation. Each box is a step (a node); arrows decide what happens next.',
      points: ['Drag a node to move it.', 'Drag from a node’s dot to another node to connect them.', 'Click an arrow to change or remove it.'],
      target: 'journey-canvas',
    },
    {
      title: 'Start node',
      body: 'Every journey begins here. Choose what starts it: a keyword the customer sends, a click on your Click-to-WhatsApp ad, or any message (default).',
      target: 'journey-start-node',
    },
    {
      title: 'Add nodes',
      body: 'Click Nodes to open the list, then click a node to add it to the canvas. Search the list if you know the name. A lock means your plan does not include that node yet.',
      target: 'journey-palette',
    },
  ];

  for (const category of JOURNEY_NODE_CATEGORIES) {
    const nodes = journeyNodesByCategory(category);
    if (nodes.length === 0) continue;
    steps.push({
      title: `${JOURNEY_NODE_CATEGORY_LABELS[category]} nodes`,
      body: CATEGORY_INTRO[category],
      points: nodes.map((n) => `${n.label} — ${n.description}`),
      target: 'journey-palette',
    });
  }

  steps.push({
    title: 'Save, test, go live',
    body: 'Save draft keeps your work without running it. Save Journey publishes it, so new conversations start using it.',
    points: ['Use Test Trigger to try the journey on your own number first.', 'Untick Active to pause a journey without deleting it.', 'Your plan sets how many journeys you can have.'],
    target: 'journey-save',
  });

  return steps;
}
