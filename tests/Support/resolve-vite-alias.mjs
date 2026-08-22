import path from 'node:path'
import { resolveConfig } from 'vite'

const root = path.resolve(process.argv[2])
const config = await resolveConfig({ root, logLevel: 'silent' }, 'build')
const alias = config.resolve.alias.find(({ find }) => find === '@nodeflow/editor')

process.stdout.write(alias?.replacement ?? '')
