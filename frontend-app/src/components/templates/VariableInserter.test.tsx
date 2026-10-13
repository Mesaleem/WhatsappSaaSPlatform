import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useRef, useState } from 'react';
import { describe, expect, it } from 'vitest';

import VariableInserter, { toVariableName, variablesIn } from './VariableInserter';

function Harness({ initial = '' }: { initial?: string }) {
  const [body, setBody] = useState(initial);
  const ref = useRef<HTMLTextAreaElement>(null);
  return (
    <>
      <textarea aria-label="Body" ref={ref} value={body} onChange={(e) => setBody(e.target.value)} />
      <VariableInserter value={body} onChange={setBody} textareaRef={ref} />
    </>
  );
}

describe('VariableInserter', () => {
  it('inserts {{name}} at the cursor without typing curly braces', async () => {
    const user = userEvent.setup();
    render(<Harness initial="Hello , thanks" />);
    const body = screen.getByLabelText('Body') as HTMLTextAreaElement;
    body.setSelectionRange(6, 6);

    await user.click(screen.getByRole('button', { name: /Add variable/ }));
    await user.type(screen.getByLabelText('Variable name'), 'Customer Name');
    await user.click(screen.getByRole('button', { name: /Insert/ }));

    expect(body.value).toBe('Hello {{customer_name}}, thanks');
  });

  it('refuses a variable name that is already in the message', async () => {
    const user = userEvent.setup();
    render(<Harness initial="Hi {{amount}}" />);

    await user.click(screen.getByRole('button', { name: /Add variable/ }));
    await user.type(screen.getByLabelText('Variable name'), 'amount');
    await user.click(screen.getByRole('button', { name: /Insert/ }));

    expect(screen.getByRole('alert').textContent).toContain('already in this message');
    expect((screen.getByLabelText('Body') as HTMLTextAreaElement).value).toBe('Hi {{amount}}');
  });

  it('lists existing variables and re-inserts one on click', async () => {
    const user = userEvent.setup();
    render(<Harness initial="Due {{amount}}" />);
    const body = screen.getByLabelText('Body') as HTMLTextAreaElement;
    body.setSelectionRange(body.value.length, body.value.length);

    await user.click(screen.getByRole('button', { name: /amount/ }));

    expect(body.value).toBe('Due {{amount}}{{amount}}');
  });

  it('normalises names and finds variables', () => {
    expect(toVariableName('  Due Date! ')).toBe('due_date');
    expect(variablesIn('Hi {{a}} {{ b }} {{a}}')).toEqual(['a', 'b']);
  });
});
