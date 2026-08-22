import path from 'node:path'
import { resolveConfig } from 'vite'

const root = path.resolve(process.argv[2])
const config = await resolveConfig({ root, logLevel: 'silent' }, 'build')

process.stdout.write(path.basename(config.configFile ?? ''))
