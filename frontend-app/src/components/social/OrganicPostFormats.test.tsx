import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import OrganicPostModal from './OrganicPostModal';
import organicPostService from '../../services/organicPostService';
import mediaService from '../../services/mediaService';
import type { OrganicPost } from '../../types/organic';

/** 2026-10-15 — the composer's Post / Reel / Story / Carousel formats. */

vi.mock('../../services/organicPostService', () => ({ default: { publish: vi.fn() } }));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));

const publish = (organicPostService as unknown as { publish: Mock }).publish;
const upload = (mediaService as unknown as { upload: Mock }).upload;

const published = { id: 1, platform: 'facebook', status: 'published', external_post_id: 'X' } as unknown as OrganicPost;

const file = (name: string, type: string) => new File(['x'], name, { type });

function open() {
  render(<OrganicPostModal onClose={() => {}} onPublished={() => {}} />);
}

async function chooseFormat(label: string) {
  await userEvent.click(screen.getByRole('radio', { name: label }));
}

beforeEach(() => {
  vi.clearAllMocks();
  publish.mockResolvedValue(published);
});

describe('OrganicPostModal formats', () => {
  it('a Reel needs a video and is sent as a reel', async () => {
    open();
    await chooseFormat('Reel');
    await userEvent.type(screen.getByPlaceholderText('Write your post…'), 'My reel');
    await userEvent.click(screen.getByRole('button', { name: 'Publish Now' }));
    expect(await screen.findByText('A Reel needs a video — upload one above.')).toBeTruthy();
    expect(publish).not.toHaveBeenCalled();

    upload.mockResolvedValue({ url: 'https://cdn.example.com/r.mp4', type: 'video' });
    fireEvent.change(screen.getByTestId('organic-media-input'), { target: { files: [file('r.mp4', 'video/mp4')] } });
    await screen.findByText('Media uploaded');
    await userEvent.click(screen.getByRole('button', { name: 'Publish Now' }));

    expect(publish).toHaveBeenCalledWith(expect.objectContaining({ platform: 'facebook', post_format: 'reel', media_url: 'https://cdn.example.com/r.mp4', media_type: 'video' }));
  });

  it('a Story needs no caption', async () => {
    open();
    await chooseFormat('Story');
    upload.mockResolvedValue({ url: 'https://cdn.example.com/s.jpg', type: 'image' });
    fireEvent.change(screen.getByTestId('organic-media-input'), { target: { files: [file('s.jpg', 'image/jpeg')] } });
    await screen.findByText('Media uploaded');
    await userEvent.click(screen.getByRole('button', { name: 'Publish Now' }));

    expect(publish).toHaveBeenCalledWith(expect.objectContaining({ post_format: 'story', caption: '' }));
  });

  it('a carousel uploads several files, needs at least two, and refuses videos for Facebook', async () => {
    open();
    await chooseFormat('Carousel');
    await userEvent.type(screen.getByPlaceholderText('Write your post…'), 'Album');

    upload.mockResolvedValueOnce({ url: 'https://cdn.example.com/1.jpg', type: 'image' });
    fireEvent.change(screen.getByTestId('organic-media-input'), { target: { files: [file('1.jpg', 'image/jpeg')] } });
    await screen.findByAltText('Item 1');
    await userEvent.click(screen.getByRole('button', { name: 'Publish Now' }));
    expect(await screen.findByText('A carousel needs 2 to 10 images or videos.')).toBeTruthy();

    upload.mockResolvedValueOnce({ url: 'https://cdn.example.com/2.mp4', type: 'video' });
    fireEvent.change(screen.getByTestId('organic-media-input'), { target: { files: [file('2.mp4', 'video/mp4')] } });
    await screen.findByText('Media (2/10)');
    await userEvent.click(screen.getByRole('button', { name: 'Publish Now' }));
    expect(await screen.findByText(/Facebook carousel can hold images only/)).toBeTruthy();

    // Instagram takes videos in a carousel.
    await userEvent.click(screen.getByRole('button', { name: 'Facebook Page' }));
    await userEvent.click(screen.getByRole('button', { name: 'Instagram' }));
    await userEvent.click(screen.getByRole('button', { name: 'Publish Now' }));
    expect(publish).toHaveBeenCalledWith(expect.objectContaining({
      platform: 'instagram',
      post_format: 'carousel',
      media_url: null,
      media_items: [{ url: 'https://cdn.example.com/1.jpg', type: 'image' }, { url: 'https://cdn.example.com/2.mp4', type: 'video' }],
    }));
  });

  it('refuses a Reel, Story or carousel for LinkedIn', async () => {
    open();
    await userEvent.click(screen.getByRole('button', { name: 'LinkedIn' }));
    await chooseFormat('Story');
    upload.mockResolvedValue({ url: 'https://cdn.example.com/s.jpg', type: 'image' });
    fireEvent.change(screen.getByTestId('organic-media-input'), { target: { files: [file('s.jpg', 'image/jpeg')] } });
    await screen.findByText('Media uploaded');
    await userEvent.click(screen.getByRole('button', { name: 'Publish Now' }));

    expect(await screen.findByText(/LinkedIn supports normal posts only/)).toBeTruthy();
    expect(publish).not.toHaveBeenCalled();
  });
});
